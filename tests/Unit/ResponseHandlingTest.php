<?php

declare(strict_types=1);

namespace Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OnPay\API\Exception\ApiException;
use OnPay\OnPayAPI;
use OnPay\StaticToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A response that is well-formed JSON but the wrong shape used to reach a DTO
 * constructor and surface as a TypeError. Callers catch ApiException, so that
 * turned a surprising response into a fatal rather than a handled failure.
 */
class ResponseHandlingTest extends TestCase
{
    /** @throws Exception */
    private function api(ResponseInterface $response): OnPayAPI
    {
        $factory = new Psr17Factory();
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            static fn (RequestInterface $request): ResponseInterface => $response
        );

        return new OnPayAPI(new StaticToken('t'), ['client_id' => 'x'], $client, $factory, $factory);
    }

    private static function json(string $body, int $status = 200): Response
    {
        return new Response($status, ['content-type' => 'application/json'], $body);
    }

    #[DataProvider('malformedSingleObjectProvider')]
    public function testMalformedSingleObjectResponsesRaiseApiException(string $body): void
    {
        $api = $this->api(self::json($body));

        $this->expectException(ApiException::class);
        $api->transaction()->getTransaction('00000000-0000-0000-0000-000000000000');
    }

    /** @return array<string, string[]> */
    public static function malformedSingleObjectProvider(): array
    {
        return [
            'empty object' => ['{}'],
            'data is null' => ['{"data":null}'],
            'data is a string' => ['{"data":"nope"}'],
            'body is a bare string' => ['"a string"'],
            'body is a bare number' => ['42'],
        ];
    }

    public function testMalformedCollectionResponseRaisesApiException(): void
    {
        $api = $this->api(self::json('{"data":"nope","meta":{"pagination":{}}}'));

        $this->expectException(ApiException::class);
        $api->transaction()->getTransactions();
    }

    public function testMissingPaginationRaisesApiException(): void
    {
        $api = $this->api(self::json('{"data":[]}'));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('no pagination metadata');
        $api->transaction()->getTransactions();
    }

    /**
     * Links are genuinely optional, so their absence must not be an error.
     */
    public function testAbsentLinksAreTolerated(): void
    {
        $api = $this->api(self::json('{"data":{"uuid":"abc","status":"active"}}'));

        $transaction = $api->transaction()->getTransaction('abc');
        $this->assertSame('abc', $transaction->uuid);
    }

    /**
     * A non-string error message used to reach the exception constructor and
     * raise a TypeError under strict_types.
     */
    #[DataProvider('hostileErrorMessageProvider')]
    public function testHostileErrorMessagesStillRaiseApiException(string $body): void
    {
        $api = $this->api(self::json($body, 400));

        $this->expectException(ApiException::class);
        $api->transaction()->getTransaction('abc');
    }

    /** @return array<string, string[]> */
    public static function hostileErrorMessageProvider(): array
    {
        return [
            'message is an array' => ['{"errors":[{"message":["a","b"]}]}'],
            'message is an int' => ['{"errors":[{"message":123}]}'],
            'message is null' => ['{"errors":[{"message":null}]}'],
            'errors is a string' => ['{"errors":"boom"}'],
        ];
    }
}
