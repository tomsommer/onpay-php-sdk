<?php

declare(strict_types=1);

namespace Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OnPay\API\Exception\ConnectionException;
use OnPay\API\Exception\TokenException;
use OnPay\Auth\TokenManager;
use OnPay\StaticToken;
use OnPay\TokenStorageInterface;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TomSommer\OAuth2\Client\Provider\OnPay as OnPayProvider;

class TokenManagerTest extends TestCase {
    /** @var RequestInterface[] */
    private array $sentRequests = [];

    /** @param Response[] $responses */
    private function manager(TokenStorageInterface $tokenStorage, array $responses = []): TokenManager {
        $this->sentRequests = [];
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(function (callable $next): callable {
            return function (RequestInterface $request, array $options) use ($next) {
                $this->sentRequests[] = $request;
                return $next($request, $options);
            };
        });

        $provider = new OnPayProvider(
            ['clientId' => 'test_id', 'redirectUri' => 'https://example.test/callback'],
            ['httpClient' => new GuzzleClient(['handler' => $handler, 'http_errors' => false])]
        );

        return new TokenManager($tokenStorage, $provider);
    }

    /**
     * @return TokenStorageInterface&MockObject
     * @throws Exception
     */
    private function storageReturning(?string $json): TokenStorageInterface {
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($json);

        return $storage;
    }

    public function testStaticTokenIsUsedAsIsAndNeverExpires(): void {
        $token = $this->manager(new StaticToken('static_token'))->getValidAccessToken();

        $this->assertSame('static_token', $token->getToken());
        $this->assertNull($token->getExpires());
        $this->assertCount(0, $this->sentRequests);
    }

    /** @throws Exception */
    public function testMissingTokenIsReported(): void {
        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('No access token stored');
        $this->manager($this->storageReturning(null))->getValidAccessToken();
    }

    /** @throws Exception */
    public function testEmptyTokenIsReported(): void {
        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('No access token stored');
        $this->manager($this->storageReturning(''))->getValidAccessToken();
    }

    /** @throws Exception */
    public function testUnreadableTokenIsReported(): void {
        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Stored token could not be read');
        $this->manager($this->storageReturning('this-is-not-a-token'))->getValidAccessToken();
    }

    /** @throws Exception */
    public function testJsonWithoutAnAccessTokenIsReported(): void {
        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Stored token could not be read');
        $this->manager($this->storageReturning('{"refresh_token":"only"}'))->getValidAccessToken();
    }

    /** @throws Exception */
    public function testUnexpiredTokenIsReturnedWithoutContactingTheProvider(): void {
        $manager = $this->manager($this->storageReturning((string) json_encode([
            'access_token' => 'valid',
            'refresh_token' => 'refresh',
            'expires' => time() + 3600,
        ])));

        $this->assertSame('valid', $manager->getValidAccessToken()->getToken());
        $this->assertCount(0, $this->sentRequests);
    }

    /**
     * Tokens written by SDK 1.x carry the fkooman shape - issued_at plus
     * expires_in - rather than an absolute expiry.
     *
     * @throws Exception
     */
    public function testLegacyTokenFormatIsReadable(): void {
        $manager = $this->manager($this->storageReturning((string) json_encode([
            'provider_id' => 'https://manage.onpay.io/oauth2/authorize|test_id',
            'issued_at' => date('Y-m-d H:i:s'),
            'access_token' => 'legacy_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'full',
        ])));

        $token = $manager->getValidAccessToken();
        $this->assertSame('legacy_token', $token->getToken());
        $this->assertGreaterThan(time(), $token->getExpires());
    }

    /** @throws Exception */
    public function testExpiredLegacyTokenIsTreatedAsExpired(): void {
        $manager = $this->manager($this->storageReturning((string) json_encode([
            'provider_id' => 'x|y',
            'issued_at' => date('Y-m-d H:i:s', time() - 7200),
            'access_token' => 'legacy_token',
            'expires_in' => 3600,
        ])));

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('no refresh token is available');
        $manager->getValidAccessToken();
    }

    /** @throws Exception */
    public function testExpiredTokenIsRefreshedAndStored(): void {
        $stored = null;
        $storage = $this->storageReturning((string) json_encode([
            'access_token' => 'expired',
            'refresh_token' => 'a_refresh_token',
            'expires' => time() - 60,
        ]));
        $storage->method('saveToken')->willReturnCallback(function (string $json) use (&$stored): void {
            $stored = json_decode($json, true);
        });

        $manager = $this->manager($storage, [
            new Response(200, ['content-type' => 'application/json'], (string) json_encode([
                'access_token' => 'refreshed',
                'refresh_token' => 'new_refresh_token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ])),
        ]);

        $this->assertSame('refreshed', $manager->getValidAccessToken()->getToken());
        $this->assertSame('refreshed', $stored['access_token']);
        $this->assertSame('new_refresh_token', $stored['refresh_token']);

        parse_str((string) $this->sentRequests[0]->getBody(), $body);
        $this->assertSame('refresh_token', $body['grant_type']);
        $this->assertSame('a_refresh_token', $body['refresh_token']);
    }

    /**
     * RFC 6749 section 6 lets the server omit refresh_token from a refresh
     * response, in which case the previous one stays valid. Storing the
     * response verbatim would drop it and make the next expiry unrecoverable.
     *
     * @throws Exception
     */
    public function testRefreshWithoutARefreshTokenKeepsTheOldOne(): void {
        $stored = null;
        $storage = $this->storageReturning((string) json_encode([
            'access_token' => 'expired',
            'refresh_token' => 'long_lived_refresh_token',
            'expires' => time() - 60,
        ]));
        $storage->method('saveToken')->willReturnCallback(function (string $json) use (&$stored): void {
            $stored = json_decode($json, true);
        });

        $manager = $this->manager($storage, [
            new Response(200, ['content-type' => 'application/json'], (string) json_encode([
                'access_token' => 'refreshed',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ])),
        ]);

        $this->assertSame('refreshed', $manager->getValidAccessToken()->getToken());
        $this->assertSame('long_lived_refresh_token', $stored['refresh_token']);
    }

    /** @throws Exception */
    public function testRejectedRefreshYieldsTokenException(): void {
        $manager = $this->manager(
            $this->storageReturning((string) json_encode([
                'access_token' => 'expired',
                'refresh_token' => 'revoked',
                'expires' => time() - 60,
            ])),
            [new Response(400, ['content-type' => 'application/json'], '{"error":"invalid_grant"}')]
        );

        $this->expectException(TokenException::class);
        $manager->getValidAccessToken();
    }

    /** @throws Exception */
    public function testUnparsableRefreshResponseYieldsConnectionException(): void {
        $manager = $this->manager(
            $this->storageReturning((string) json_encode([
                'access_token' => 'expired',
                'refresh_token' => 'a_refresh_token',
                'expires' => time() - 60,
            ])),
            [new Response(200, ['content-type' => 'text/html'], '<html>gateway error</html>')]
        );

        $this->expectException(ConnectionException::class);
        $manager->getValidAccessToken();
    }

    /** @throws Exception */
    public function testExchangeAuthorizationCodeStoresTheToken(): void {
        $stored = null;
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('saveToken')->willReturnCallback(function (string $json) use (&$stored): void {
            $stored = json_decode($json, true);
        });

        $manager = $this->manager($storage, [
            new Response(200, ['content-type' => 'application/json'], (string) json_encode([
                'access_token' => 'granted',
                'refresh_token' => 'granted_refresh',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ])),
        ]);

        $token = $manager->exchangeAuthorizationCode('a_code');

        $this->assertSame('granted', $token->getToken());
        $this->assertSame('granted', $stored['access_token']);
        $this->assertSame('granted_refresh', $stored['refresh_token']);

        parse_str((string) $this->sentRequests[0]->getBody(), $body);
        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertSame('a_code', $body['code']);
    }

    /** @throws Exception */
    public function testRejectedAuthorizationCodeYieldsTokenException(): void {
        $manager = $this->manager($this->createMock(TokenStorageInterface::class), [
            new Response(400, ['content-type' => 'application/json'], (string) json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Authorization code is invalid',
            ])),
        ]);

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Authorization code is invalid');
        $manager->exchangeAuthorizationCode('bad_code');
    }
}
