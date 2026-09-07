<?php

declare(strict_types=1);

namespace Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use League\OAuth2\Client\Provider\AbstractProvider;
use OnPay\API\Exception\ConnectionException;
use OnPay\API\Exception\TokenException;
use OnPay\OnPayAPI;
use OnPay\TokenStorageInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class Oauth2ClientTest extends TestCase {
    protected string $clientId = 'test_client_id';
    protected string $baseUri = 'https://api.test.onpay.io';
    protected string $baseAuthUri = 'https://manage.test.onpay.io';
    protected string $redirectUri = 'https://example.test/callback';

    /** @var RequestInterface[] */
    protected array $sentRequests = [];

    /**
     * Builds an API instance whose Guzzle client answers with $responses in order.
     * Guzzle satisfies PSR-18, so one mock covers both the API calls made by the
     * SDK and the token-endpoint calls made by league/oauth2-client.
     *
     * @param GuzzleResponse[] $responses
     */
    private function api(array $responses, ?TokenStorageInterface $tokenStorage = null, array $extraOptions = []): OnPayAPI {
        $this->sentRequests = [];
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(function (callable $next) {
            return function (RequestInterface $request, array $options) use ($next) {
                $this->sentRequests[] = $request;
                return $next($request, $options);
            };
        });
        $client = new GuzzleClient(['handler' => $handler, 'http_errors' => false]);

        return new OnPayAPI(
            $tokenStorage ?? $this->createMock(TokenStorageInterface::class),
            array_merge([
                'base_uri' => $this->baseUri,
                'base_authorize_uri' => $this->baseAuthUri,
                'client_id' => $this->clientId,
                'redirect_uri' => $this->redirectUri,
            ], $extraOptions),
            $client
        );
    }

    public function testAuthorizeUrlIsExpectedFormat(): void {
        $url = $this->api([])->authorize();

        $expectedPath = $this->baseAuthUri . '/oauth2/authorize';
        $this->assertStringContainsString($expectedPath, $url);
        parse_str(str_replace($expectedPath . '?', '', $url), $query);

        $this->assertArrayHasKey('client_id', $query);
        $this->assertArrayHasKey('redirect_uri', $query);
        $this->assertArrayHasKey('scope', $query);
        $this->assertArrayHasKey('state', $query);
        $this->assertArrayHasKey('response_type', $query);

        $this->assertEquals($this->clientId, $query['client_id']);
        $this->assertEquals($this->redirectUri, $query['redirect_uri']);
        $this->assertEquals('full', $query['scope']);
        $this->assertEquals('code', $query['response_type']);
    }

    public function testAuthorizeUrlCarriesPkceChallengeWhenEnabled(): void {
        $api = $this->api([], null, ['pkce_method' => AbstractProvider::PKCE_METHOD_S256]);
        $url = $api->authorize();

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty($api->getPkceCode());
    }

    public function testFinishAuthorizeExchangesCodeAndStoresToken(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->once())
            ->method('saveToken')
            ->with($this->callback(static function (string $json): bool {
                $decoded = json_decode($json, true);
                return 'new_access_token' === $decoded['access_token']
                    && 'new_refresh_token' === $decoded['refresh_token'];
            }));

        $api = $this->api([
            new GuzzleResponse(200, ['content-type' => 'application/json'], json_encode([
                'access_token' => 'new_access_token',
                'refresh_token' => 'new_refresh_token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ])),
        ], $tokenStorage);

        $api->finishAuthorize('authorization_code_from_callback');

        $request = $this->sentRequests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame($this->baseUri . '/oauth2/access_token', (string) $request->getUri());
        parse_str((string) $request->getBody(), $body);
        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertSame('authorization_code_from_callback', $body['code']);
        $this->assertSame($this->clientId, $body['client_id']);
    }

    public function testStateIsExposedAfterAuthorize(): void {
        $api = $this->api([]);
        $url = $api->authorize();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($api->getState());
        $this->assertSame($query['state'], $api->getState());
    }

    /**
     * Without a state check the callback cannot be told apart from one an
     * attacker made the visitor follow, so a mismatch must not spend the code.
     */
    public function testMismatchedStateRejectsTheCallbackWithoutSpendingTheCode(): void {
        $api = $this->api([
            new GuzzleResponse(200, ['content-type' => 'application/json'], (string) json_encode([
                'access_token' => 'should_never_be_requested',
            ])),
        ]);
        $api->authorize();

        try {
            $api->finishAuthorize('a_code', 'state_from_attacker', $api->getState());
            $this->fail('Expected a TokenException');
        } catch (TokenException $e) {
            $this->assertStringContainsString('state mismatch', $e->getMessage());
        }

        $this->assertCount(0, $this->sentRequests, 'the token endpoint must not be contacted');
    }

    public function testMatchingStateAllowsTheExchange(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->once())->method('saveToken');

        $api = $this->api([
            new GuzzleResponse(200, ['content-type' => 'application/json'], (string) json_encode([
                'access_token' => 'granted',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ])),
        ], $tokenStorage);
        $api->authorize();
        $state = $api->getState();

        $api->finishAuthorize('a_code', $state, $state);
        $this->assertCount(1, $this->sentRequests);
    }

    /** A half-supplied pair is a programming error, not a pass. */
    public function testStateCheckCannotBeHalfSupplied(): void {
        $api = $this->api([]);
        $api->authorize();

        $this->expectException(TokenException::class);
        $api->finishAuthorize('a_code', $api->getState(), null);
    }

    public function testFinishAuthorizeThrowsTokenExceptionOnRejectedCode(): void {
        $api = $this->api([
            new GuzzleResponse(400, ['content-type' => 'application/json'], json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Authorization code is invalid',
            ])),
        ]);

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Authorization code is invalid');
        $api->finishAuthorize('bad_code');
    }

    public function testFinishAuthorizeThrowsConnectionExceptionOnUnparsableResponse(): void {
        $api = $this->api([
            new GuzzleResponse(200, ['content-type' => 'text/html'], '<html>gateway error</html>'),
        ]);

        $this->expectException(ConnectionException::class);
        $api->finishAuthorize('some_code');
    }

    public function testNonExpiredAccessTokenIsUsedAsIs(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(json_encode([
            'access_token' => 'valid_access_token',
            'refresh_token' => 'valid_refresh_token',
            'expires' => time() + 3600,
        ]));
        $tokenStorage->expects($this->never())->method('saveToken');

        $api = $this->api([
            new GuzzleResponse(200, ['content-type' => 'application/json'], '{"ping":"pong"}'),
        ], $tokenStorage);

        $this->assertSame(['ping' => 'pong'], $api->ping());
        $this->assertCount(1, $this->sentRequests);
        $this->assertSame('Bearer valid_access_token', $this->sentRequests[0]->getHeaderLine('Authorization'));
    }

    public function testExpiredAccessTokenIsRefreshedAndStored(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(json_encode([
            'access_token' => 'expired_access_token',
            'refresh_token' => 'valid_refresh_token',
            'expires' => time() - 60,
        ]));
        $tokenStorage->expects($this->once())
            ->method('saveToken')
            ->with($this->callback(static function (string $json): bool {
                return 'refreshed_access_token' === json_decode($json, true)['access_token'];
            }));

        $api = $this->api([
            new GuzzleResponse(200, ['content-type' => 'application/json'], json_encode([
                'access_token' => 'refreshed_access_token',
                'refresh_token' => 'new_refresh_token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ])),
            new GuzzleResponse(200, ['content-type' => 'application/json'], '{"ping":"pong"}'),
        ], $tokenStorage);

        $this->assertSame(['ping' => 'pong'], $api->ping());
        $this->assertCount(2, $this->sentRequests);

        parse_str((string) $this->sentRequests[0]->getBody(), $body);
        $this->assertSame('refresh_token', $body['grant_type']);
        $this->assertSame('valid_refresh_token', $body['refresh_token']);
        $this->assertSame('Bearer refreshed_access_token', $this->sentRequests[1]->getHeaderLine('Authorization'));
    }

    /**
     * RFC 6749 section 6 lets the server omit refresh_token from a refresh
     * response, in which case the previous one stays valid. Storing the response
     * verbatim would drop it and make the next expiry unrecoverable.
     */
    public function testRefreshResponseWithoutRefreshTokenKeepsTheOldOne(): void {
        $stored = null;
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(json_encode([
            'access_token' => 'expired_access_token',
            'refresh_token' => 'long_lived_refresh_token',
            'expires' => time() - 60,
        ]));
        $tokenStorage->method('saveToken')->willReturnCallback(
            function (string $json) use (&$stored): void {
                $stored = json_decode($json, true);
            }
        );

        $api = $this->api([
            new GuzzleResponse(200, ['content-type' => 'application/json'], json_encode([
                'access_token' => 'refreshed_access_token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ])),
            new GuzzleResponse(200, ['content-type' => 'application/json'], '{"ping":"pong"}'),
        ], $tokenStorage);

        $this->assertSame(['ping' => 'pong'], $api->ping());
        $this->assertSame('refreshed_access_token', $stored['access_token']);
        $this->assertSame('long_lived_refresh_token', $stored['refresh_token']);
    }

    public function testMissingRefreshTokenMakesIsAuthorizedFalse(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(json_encode([
            'access_token' => 'expired_access_token',
            'expires' => time() - 60,
        ]));

        $this->assertFalse($this->api([], $tokenStorage)->isAuthorized());
    }

    public function testInvalidGrantOnRefreshMakesIsAuthorizedFalse(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(json_encode([
            'access_token' => 'expired_access_token',
            'refresh_token' => 'revoked_refresh_token',
            'expires' => time() - 60,
        ]));

        $api = $this->api([
            new GuzzleResponse(400, ['content-type' => 'application/json'], json_encode([
                'error' => 'invalid_grant',
            ])),
        ], $tokenStorage);

        $this->assertFalse($api->isAuthorized());
    }

    public function testUnparsableRefreshResponseThrowsConnectionException(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(json_encode([
            'access_token' => 'expired_access_token',
            'refresh_token' => 'valid_refresh_token',
            'expires' => time() - 60,
        ]));

        $api = $this->api([
            new GuzzleResponse(200, ['content-type' => 'text/html'], '<html>gateway error</html>'),
        ], $tokenStorage);

        $this->expectException(ConnectionException::class);
        $api->ping();
    }

    public function testUnreadableStoredTokenThrowsTokenException(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn('this-is-not-a-token');

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Stored token could not be read');
        $this->api([], $tokenStorage)->ping();
    }
}
