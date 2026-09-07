<?php

declare(strict_types=1);

namespace Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OnPay\API\Exception\ApiException;
use OnPay\API\Exception\ConnectionException;
use OnPay\API\Exception\TokenException;
use OnPay\Auth\TokenManager;
use OnPay\Http\ApiClient;
use OnPay\StaticToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TomSommer\OAuth2\Client\Provider\OnPay as OnPayProvider;

class ApiClientTest extends TestCase {
    private const BASE_URI = 'https://api.onpay.io';

    /**
     * A client backed by a static token and a stubbed PSR-18 client that always
     * answers with $response. The sent request lands in $captured.
     *
     * @throws Exception
     */
    private function client(ResponseInterface $response, ?RequestInterface &$captured = null): ApiClient {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request) use (&$captured, $response): ResponseInterface {
                $captured = $request;
                return $response;
            }
        );

        return $this->clientWith($httpClient);
    }

    /** @throws Exception */
    private function clientWith(ClientInterface $httpClient): ApiClient {
        $factory = new Psr17Factory();

        return new ApiClient(
            $httpClient,
            $factory,
            $factory,
            new TokenManager(
                new StaticToken('static_token'),
                new OnPayProvider(['clientId' => 'test_id', 'redirectUri' => 'https://example.test/'])
            ),
            self::BASE_URI,
            'php-sdk/test',
        );
    }

    public function testGetSendsBearerTokenAndDecodesJson(): void {
        $client = $this->client(new Response(200, ['content-type' => 'application/json'], '{"ping":"pong"}'), $captured);

        $this->assertSame(['ping' => 'pong'], $client->get('ping'));
        $this->assertSame('GET', $captured->getMethod());
        $this->assertSame(self::BASE_URI . '/v1/ping', (string) $captured->getUri());
        $this->assertSame('Bearer static_token', $captured->getHeaderLine('Authorization'));
        $this->assertSame('php-sdk/test', $captured->getHeaderLine('User-Agent'));
    }

    public function testGetSendsNoContentTypeWithoutABody(): void {
        $client = $this->client(new Response(200, ['content-type' => 'application/json'], '{}'), $captured);
        $client->get('ping');

        $this->assertSame('', $captured->getHeaderLine('Content-Type'));
    }

    public function testPostSendsJsonBody(): void {
        $client = $this->client(new Response(200, ['content-type' => 'application/json'], '{"ok":true}'), $captured);

        $this->assertSame(['ok' => true], $client->post('transactions', ['amount' => 100]));
        $this->assertSame('POST', $captured->getMethod());
        $this->assertSame('application/json', $captured->getHeaderLine('Content-Type'));
        $this->assertSame('{"amount":100}', (string) $captured->getBody());
    }

    public function testPostDoesNotEscapeSlashes(): void {
        $client = $this->client(new Response(200, ['content-type' => 'application/json'], '{}'), $captured);
        $client->post('transactions', ['url' => 'https://example.test/path']);

        $this->assertStringContainsString('https://example.test/path', (string) $captured->getBody());
    }

    public function testEmptyBodyDecodesToNull(): void {
        $this->assertNull($this->client(new Response(204, [], ''))->get('transactions'));
    }

    public function testApiErrorMessageIsExtractedFromJsonBody(): void {
        $client = $this->client(
            new Response(400, ['content-type' => 'application/json'], '{"errors":[{"message":"Bad amount"}]}')
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Bad amount');
        $client->get('transactions');
    }

    public function testNotFoundYieldsApiException(): void {
        $client = $this->client(new Response(404, [], ''));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Not found');
        $client->get('transactions');
    }

    #[DataProvider('tokenErrorStatusProvider')]
    public function testTokenStatusesYieldTokenException(int $status): void {
        $client = $this->client(new Response($status, [], ''));

        $this->expectException(TokenException::class);
        $client->get('transactions');
    }

    /** @return array<string, int[]> */
    public static function tokenErrorStatusProvider(): array {
        return ['unauthorized' => [401], 'forbidden' => [403]];
    }

    public function testMalformedJsonYieldsApiException(): void {
        $client = $this->client(new Response(200, ['content-type' => 'application/json'], 'not-json'));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to decode JSON body-response');
        $client->get('transactions');
    }

    /**
     * A non-JSON error body carries no message to extract, so the status alone
     * has to survive to the caller.
     */
    public function testNonJsonErrorBodyStillYieldsApiExceptionWithTheStatus(): void {
        $client = $this->client(new Response(502, ['content-type' => 'text/html'], '<html>gateway</html>'));

        try {
            $client->get('transactions');
            $this->fail('Expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(502, $e->getCode());
        }
    }

    /** @throws Exception */
    public function testTransportFailureYieldsConnectionException(): void {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException(
            new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {}
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('connection refused');
        $this->clientWith($httpClient)->get('transactions');
    }

    /** @throws Exception */
    public function testLoggerReceivesFailedResponses(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->equalTo('OnPay HTTP request failed'),
                $this->callback(static function (array $context): bool {
                    return 500 === ($context['status'] ?? null)
                        && 'GET' === ($context['method'] ?? null)
                        && str_ends_with((string) ($context['uri'] ?? ''), '/v1/transactions')
                        && str_contains((string) ($context['response'] ?? ''), 'failed-body');
                })
            );

        $client = $this->client(new Response(500, [], 'failed-body'));
        $client->setLogger($logger);

        $this->expectException(ApiException::class);
        $client->get('transactions');
    }

    /** @throws Exception */
    public function testSuccessfulResponsesAreNotLogged(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $client = $this->client(new Response(200, ['content-type' => 'application/json'], '{}'));
        $client->setLogger($logger);
        $client->get('ping');
    }

    public function testGetPlatformReturnsTheConfiguredValue(): void {
        $this->assertSame('php-sdk/test', $this->client(new Response(200, [], ''))->getPlatform());
    }
}
