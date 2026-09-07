<?php

namespace Tests\Unit;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use OnPay\API\Exception\ApiException;
use OnPay\API\Exception\ConnectionException;
use OnPay\API\Exception\TokenException;
use OnPay\OnPayAPI;
use OnPay\StaticToken;
use OnPay\TokenStorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class OnPayApiTest extends TestCase {
    /** @throws Exception */
    public function testInitializeThrowsOnMissingRequiredParameterClientId(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn('test_token');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Required options not defined: client_id');
        new OnPayAPI($tokenStorage, ['redirect_uri' => 'test_uri']);
    }

    /** @throws Exception */
    public function testInitializeThrowsOnMissingRequiredParameterRedirectUri(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn('test_token');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Required options not defined: redirect_uri');
        new OnPayAPI($tokenStorage, ['client_id' => 'test_id']);
    }

    /** @throws Exception */
    public function testInitializeApi(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn('test_token');
        $this->expectNotToPerformAssertions();
        new OnPayAPI($tokenStorage, ['client_id' => 'test_id', 'redirect_uri' => 'test_uri']);
    }

    public function testStaticTokenDoesNotRequireRedirectUri(): void {
        $this->expectNotToPerformAssertions();
        new OnPayAPI(new StaticToken('static_token'), ['client_id' => 'test_id']);
    }

    /** @throws Exception */
    public function testInitializeApiWithLegacyNumericStringGatewayId(): void {
        $api = new OnPayAPI($this->createMock(TokenStorageInterface::class), [
            'client_id' => 'test_id',
            'redirect_uri' => 'test_uri',
            'gateway_id' => '1234',
        ]);
        $this->assertStringContainsString('/1234/oauth2/authorize', $api->getProvider()->getBaseAuthorizationUrl());
    }

    /** @throws Exception */
    public function testInitializeApiWithLegacyNumericIntGatewayId(): void {
        $api = new OnPayAPI($this->createMock(TokenStorageInterface::class), [
            'client_id' => 'test_id',
            'redirect_uri' => 'test_uri',
            'gateway_id' => 1234,
        ]);
        $this->assertStringContainsString('/1234/oauth2/authorize', $api->getProvider()->getBaseAuthorizationUrl());
    }

    /** @throws Exception */
    public function testInitializeApiWithNewAlphanumericGatewayId(): void {
        $api = new OnPayAPI($this->createMock(TokenStorageInterface::class), [
            'client_id' => 'test_id',
            'redirect_uri' => 'test_uri',
            'gateway_id' => 'A5KM3QX7B',
        ]);
        $this->assertStringContainsString('/A5KM3QX7B/oauth2/authorize', $api->getProvider()->getBaseAuthorizationUrl());
    }

    /** @throws Exception */
    #[DataProvider('invalidGatewayIdProvider')]
    public function testInitializeThrowsOnInvalidGatewayId(string $gatewayId): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('gateway_id must be a non-empty alphanumeric value');
        new OnPayAPI($this->createMock(TokenStorageInterface::class), [
            'client_id' => 'test_id',
            'redirect_uri' => 'test_uri',
            'gateway_id' => $gatewayId,
        ]);
    }

    /** @return array<string, string[]> */
    public static function invalidGatewayIdProvider(): array {
        return [
            'empty' => [''],
            'lowercase' => ['a5km3qx7b'],
            'hyphen' => ['A5-KM3'],
            'space' => ['A5 KM3'],
        ];
    }

    public function testAuthorizeUrlContainsClientIdAndScope(): void {
        $api = new OnPayAPI(new StaticToken('static_token'), ['client_id' => 'test_id']);
        $url = $api->authorize();
        $this->assertStringContainsString('client_id=test_id', $url);
        $this->assertStringContainsString('scope=full', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    public function testGetSendsBearerTokenAndDecodesJson(): void {
        $api = $this->apiWithResponse(new Response(200, ['content-type' => 'application/json'], '{"ping":"pong"}'), $captured);

        $this->assertSame(['ping' => 'pong'], $api->ping());
        $this->assertSame('GET', $captured->getMethod());
        $this->assertSame('https://api.onpay.io/v1/ping', (string) $captured->getUri());
        $this->assertSame('Bearer static_token', $captured->getHeaderLine('Authorization'));
        $this->assertStringStartsWith('php-sdk/', $captured->getHeaderLine('User-Agent'));
    }

    public function testPostSendsJsonBody(): void {
        $api = $this->apiWithResponse(new Response(200, ['content-type' => 'application/json'], '{"ok":true}'), $captured);

        $this->assertSame(['ok' => true], $api->post('transactions', ['amount' => 100]));
        $this->assertSame('POST', $captured->getMethod());
        $this->assertSame('application/json', $captured->getHeaderLine('Content-Type'));
        $this->assertSame('{"amount":100}', (string) $captured->getBody());
    }

    public function testLastHttpRequestAndResponseAreRecorded(): void {
        $api = $this->apiWithResponse(new Response(200, ['content-type' => 'application/json'], '{"ping":"pong"}'), $captured);
        $api->ping();

        $this->assertSame('GET', $api->getLastHttpRequest()->getMethod());
        $this->assertSame('https://api.onpay.io/v1/ping', $api->getLastHttpRequest()->getUri());
        $this->assertSame(200, $api->getLastHttpResponse()->getStatusCode());
        $this->assertSame('{"ping":"pong"}', $api->getLastHttpResponse()->getBody());
    }

    public function testApiErrorMessageIsExtractedFromJsonBody(): void {
        $api = $this->apiWithResponse(
            new Response(400, ['content-type' => 'application/json'], '{"errors":[{"message":"Bad amount"}]}'),
            $captured
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Bad amount');
        $api->get('transactions');
    }

    public function testNotFoundYieldsApiException(): void {
        $api = $this->apiWithResponse(new Response(404, [], ''), $captured);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Not found');
        $api->get('transactions');
    }

    #[DataProvider('tokenErrorStatusProvider')]
    public function testTokenStatusesYieldTokenException(int $status): void {
        $api = $this->apiWithResponse(new Response($status, [], ''), $captured);

        $this->expectException(TokenException::class);
        $api->get('transactions');
    }

    /** @return array<string, int[]> */
    public static function tokenErrorStatusProvider(): array {
        return ['unauthorized' => [401], 'forbidden' => [403]];
    }

    public function testIsAuthorizedReturnsFalseOnTokenError(): void {
        $api = $this->apiWithResponse(new Response(401, [], ''), $captured);
        $this->assertFalse($api->isAuthorized());
    }

    public function testIsAuthorizedReturnsTrueOnPing(): void {
        $api = $this->apiWithResponse(new Response(200, ['content-type' => 'application/json'], '{}'), $captured);
        $this->assertTrue($api->isAuthorized());
    }

    public function testMalformedJsonYieldsApiException(): void {
        $api = $this->apiWithResponse(new Response(200, ['content-type' => 'application/json'], 'not-json'), $captured);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to decode JSON body-response');
        $api->get('transactions');
    }

    /** @throws Exception */
    public function testTransportFailureYieldsConnectionException(): void {
        $factory = new Psr17Factory();
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willThrowException(
            new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {}
        );

        $api = new OnPayAPI(new StaticToken('static_token'), ['client_id' => 'test_id'], $client, $factory, $factory);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('connection refused');
        $api->get('transactions');
    }

    /** @throws Exception */
    public function testMissingStoredTokenYieldsTokenException(): void {
        $factory = new Psr17Factory();
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $api = new OnPayAPI(
            $tokenStorage,
            ['client_id' => 'test_id', 'redirect_uri' => 'test_uri'],
            $this->createMock(ClientInterface::class),
            $factory,
            $factory
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('No access token stored');
        $api->get('transactions');
    }

    /** @throws Exception */
    public function testExpiredTokenWithoutRefreshTokenYieldsTokenException(): void {
        $factory = new Psr17Factory();
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(json_encode([
            'access_token' => 'expired',
            'expires' => time() - 60,
        ]));

        $api = new OnPayAPI(
            $tokenStorage,
            ['client_id' => 'test_id', 'redirect_uri' => 'test_uri'],
            $this->createMock(ClientInterface::class),
            $factory,
            $factory
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('no refresh token is available');
        $api->get('transactions');
    }

    /** @throws Exception */
    public function testLegacyFkoomanTokenFormatIsStillReadable(): void {
        $factory = new Psr17Factory();
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(json_encode([
            'provider_id' => 'https://manage.onpay.io/oauth2/authorize|test_id',
            'issued_at' => date('Y-m-d H:i:s'),
            'access_token' => 'legacy_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'full',
        ]));

        $captured = null;
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request) use (&$captured): ResponseInterface {
                $captured = $request;
                return new Response(200, ['content-type' => 'application/json'], '{}');
            }
        );

        $api = new OnPayAPI(
            $tokenStorage,
            ['client_id' => 'test_id', 'redirect_uri' => 'test_uri'],
            $client,
            $factory,
            $factory
        );
        $api->ping();

        $this->assertSame('Bearer legacy_token', $captured->getHeaderLine('Authorization'));
    }

    /** @throws Exception */
    public function testLoggerReceivesFailedResponses(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->equalTo('OnPay HTTP request failed'),
                $this->callback(static function (array $context): bool {
                    return ($context['status'] ?? null) === 500
                        && 'GET' === ($context['method'] ?? null)
                        && false !== strpos((string) ($context['response'] ?? ''), 'failed-body');
                })
            );

        $api = $this->apiWithResponse(new Response(500, [], 'failed-body'), $captured);
        $api->setLogger($logger);

        try {
            $api->get('transactions');
        } catch (ApiException $e) {
            // Expected, the assertion is on the logger.
        }
    }

    /** @throws Exception */
    public function testSetHttpClientReplacesTheClient(): void {
        $factory = new Psr17Factory();
        $api = new OnPayAPI(
            new StaticToken('static_token'),
            ['client_id' => 'test_id'],
            $this->createMock(ClientInterface::class),
            $factory,
            $factory
        );

        $replacement = $this->createMock(ClientInterface::class);
        $replacement->expects($this->once())
            ->method('sendRequest')
            ->willReturn(new Response(200, ['content-type' => 'application/json'], '{"ping":"pong"}'));
        $api->setHttpClient($replacement);

        $this->assertSame(['ping' => 'pong'], $api->ping());
    }

    /**
     * Builds an API instance backed by a static token and a stubbed PSR-18 client
     * that always answers with $response. The sent request lands in $captured.
     *
     * @throws Exception
     */
    private function apiWithResponse(ResponseInterface $response, ?RequestInterface &$captured): OnPayAPI {
        $factory = new Psr17Factory();
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request) use (&$captured, $response): ResponseInterface {
                $captured = $request;
                return $response;
            }
        );

        return new OnPayAPI(new StaticToken('static_token'), ['client_id' => 'test_id'], $client, $factory, $factory);
    }
}
