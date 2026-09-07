<?php

declare(strict_types=1);

namespace Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OnPay\API\Exception\TokenException;
use OnPay\Auth\TokenManager;
use OnPay\OnPayAPI;
use OnPay\TokenStorageInterface;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TomSommer\OAuth2\Client\Provider\OnPay as OnPayProvider;

class TokenLifecycleTest extends TestCase
{
    /** @var RequestInterface[] */
    private array $sent = [];

    /**
     * @param Response[] $responses
     * @throws Exception
     */
    private function api(TokenStorageInterface $storage, array $responses): OnPayAPI
    {
        $this->sent = [];
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(function (callable $next): callable {
            return function (RequestInterface $request, array $options) use ($next) {
                $this->sent[] = $request;
                return $next($request, $options);
            };
        });
        $client = new GuzzleClient(['handler' => $handler, 'http_errors' => false]);

        return new OnPayAPI($storage, ['client_id' => 'x', 'redirect_uri' => 'y'], $client);
    }

    /**
     * @return TokenStorageInterface&MockObject
     * @throws Exception
     */
    private function storage(?string $json): TokenStorageInterface
    {
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($json);

        return $storage;
    }

    /**
     * expires_in is relative to when the token was issued, which a stored blob no
     * longer knows. Recomputing it from the current time on every read would make
     * the token perpetually fresh however old it really is.
     *
     * @throws Exception
     */
    public function testStoredExpiresInDoesNotMakeATokenImmortal(): void
    {
        $manager = new TokenManager(
            $this->storage((string) json_encode(['access_token' => 't', 'expires_in' => 3600])),
            new OnPayProvider(['clientId' => 'x', 'redirectUri' => 'y'])
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('no refresh token is available');
        $manager->getValidAccessToken();
    }

    /** @throws Exception */
    public function testUnparsableIssuedAtIsTreatedAsExpired(): void
    {
        $manager = new TokenManager(
            $this->storage((string) json_encode([
                'access_token' => 't',
                'issued_at' => 'not a date',
                'expires_in' => 3600,
            ])),
            new OnPayProvider(['clientId' => 'x', 'redirectUri' => 'y'])
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('no refresh token is available');
        $manager->getValidAccessToken();
    }

    /** @throws Exception */
    public function testTokenExpiringInsideTheSkewMarginIsRenewedEarly(): void
    {
        $storage = $this->storage((string) json_encode([
            'access_token' => 'about_to_die',
            'refresh_token' => 'r',
            'expires' => time() + 5,
        ]));

        $api = $this->api($storage, [
            new Response(200, ['content-type' => 'application/json'], (string) json_encode([
                'access_token' => 'renewed', 'token_type' => 'Bearer', 'expires_in' => 3600,
            ])),
            new Response(200, ['content-type' => 'application/json'], '{"data":{}}'),
        ]);
        $api->ping();

        $this->assertCount(2, $this->sent);
        $this->assertSame('Bearer renewed', $this->sent[1]->getHeaderLine('Authorization'));
    }

    /** @throws Exception */
    public function testExpiresZeroMeansNoExpiryRatherThanAnError(): void
    {
        $api = $this->api(
            $this->storage((string) json_encode(['access_token' => 'forever', 'expires' => 0])),
            [new Response(200, ['content-type' => 'application/json'], '{"data":{"pong":"1"}}')]
        );

        $this->assertSame(['data' => ['pong' => '1']], $api->ping());
        $this->assertSame('Bearer forever', $this->sent[0]->getHeaderLine('Authorization'));
    }

    /** @throws Exception */
    public function testNonStringAccessTokenIsRejected(): void
    {
        $manager = new TokenManager(
            $this->storage((string) json_encode(['access_token' => ['not', 'a', 'string']])),
            new OnPayProvider(['clientId' => 'x', 'redirectUri' => 'y'])
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Stored token could not be read');
        $manager->getValidAccessToken();
    }

    /**
     * OnPay is the authority on whether a token is good. If it says no and we
     * have something to renew with, that is worth one attempt before failing.
     *
     * @throws Exception
     */
    public function testA401IsRetriedOnceWithAFreshToken(): void
    {
        $storage = $this->storage((string) json_encode([
            'access_token' => 'stale', 'refresh_token' => 'r', 'expires' => time() + 3600,
        ]));

        $api = $this->api($storage, [
            new Response(401, [], ''),
            new Response(200, ['content-type' => 'application/json'], (string) json_encode([
                'access_token' => 'fresh', 'token_type' => 'Bearer', 'expires_in' => 3600,
            ])),
            new Response(200, ['content-type' => 'application/json'], '{"data":{"pong":"1"}}'),
        ]);

        $this->assertSame(['data' => ['pong' => '1']], $api->ping());
        $this->assertCount(3, $this->sent);
        $this->assertSame('Bearer stale', $this->sent[0]->getHeaderLine('Authorization'));
        $this->assertSame('Bearer fresh', $this->sent[2]->getHeaderLine('Authorization'));
    }

    /** @throws Exception */
    public function testA401IsNotRetriedTwice(): void
    {
        $storage = $this->storage((string) json_encode([
            'access_token' => 'stale', 'refresh_token' => 'r', 'expires' => time() + 3600,
        ]));

        $api = $this->api($storage, [
            new Response(401, [], ''),
            new Response(200, ['content-type' => 'application/json'], (string) json_encode([
                'access_token' => 'fresh', 'token_type' => 'Bearer', 'expires_in' => 3600,
            ])),
            new Response(401, [], ''),
        ]);

        $this->expectException(TokenException::class);
        try {
            $api->ping();
        } finally {
            $this->assertCount(3, $this->sent, 'exactly one retry');
        }
    }

    /** @throws Exception */
    public function testA401WithoutARefreshTokenIsNotRetried(): void
    {
        $api = $this->api(
            $this->storage((string) json_encode(['access_token' => 'static_ish'])),
            [new Response(401, [], '')]
        );

        $this->expectException(TokenException::class);
        try {
            $api->ping();
        } finally {
            $this->assertCount(1, $this->sent, 'nothing to renew with, so no second attempt');
        }
    }

    /** @throws Exception */
    public function testMalformedTokenResponseSurfacesAsATokenException(): void
    {
        $manager = new TokenManager(
            $this->storage((string) json_encode([
                'access_token' => 'old', 'refresh_token' => 'r', 'expires' => time() - 60,
            ])),
            new OnPayProvider(
                ['clientId' => 'x', 'redirectUri' => 'y'],
                ['httpClient' => new GuzzleClient([
                    'handler' => HandlerStack::create(new MockHandler([
                        new Response(200, ['content-type' => 'application/json'], '{"no_access_token":true}'),
                    ])),
                    'http_errors' => false,
                ])]
            )
        );

        $this->expectException(TokenException::class);
        $manager->getValidAccessToken();
    }
}
