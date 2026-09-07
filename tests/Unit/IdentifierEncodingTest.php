<?php

declare(strict_types=1);

namespace Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OnPay\OnPayAPI;
use OnPay\StaticToken;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Identifiers are interpolated into the request path. Left unencoded, a value
 * containing "?" turns the intended suffix into a query string, so a refund
 * lands on /capture - a different, equally valid call with the same body shape.
 */
class IdentifierEncodingTest extends TestCase
{
    /** @var string[] */
    private array $sent = [];

    /** @throws Exception */
    private function api(): OnPayAPI
    {
        $this->sent = [];
        $factory = new Psr17Factory();
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request): ResponseInterface {
                $this->sent[] = (string) $request->getUri();
                return new Response(200, ['content-type' => 'application/json'], '{"data":{"uuid":"x"},"links":{}}');
            }
        );

        return new OnPayAPI(new StaticToken('t'), ['client_id' => 'x'], $client, $factory, $factory);
    }

    private const HOSTILE = '1234/capture?';

    /** @throws Exception */
    public function testRefundKeepsItsActionSuffix(): void
    {
        $api = $this->api();
        $api->transaction()->refundTransaction(self::HOSTILE, 500);

        $this->assertStringEndsWith('/refund', $this->sent[0]);
        $this->assertStringNotContainsString('/capture?', $this->sent[0]);
    }

    /** @throws Exception */
    public function testCaptureKeepsItsActionSuffix(): void
    {
        $api = $this->api();
        $api->transaction()->captureTransaction('1234/refund?');

        $this->assertStringEndsWith('/capture', $this->sent[0]);
        $this->assertStringNotContainsString('/refund?', $this->sent[0]);
    }

    /** @throws Exception */
    public function testCancelTransactionKeepsItsActionSuffix(): void
    {
        $api = $this->api();
        $api->transaction()->cancelTransaction(self::HOSTILE);

        $this->assertStringEndsWith('/cancel', $this->sent[0]);
    }

    /** @throws Exception */
    public function testSubscriptionCancelKeepsItsActionSuffix(): void
    {
        $api = $this->api();
        $api->subscription()->cancelSubscription('abc/authorize?');

        $this->assertStringEndsWith('/cancel', $this->sent[0]);
        $this->assertStringNotContainsString('/authorize?', $this->sent[0]);
    }

    /** @throws Exception */
    public function testSubscriptionAuthorizeKeepsItsActionSuffix(): void
    {
        $api = $this->api();
        $api->subscription()->createTransactionFromSubscription('abc/cancel?', 100, 'order-1');

        $this->assertStringEndsWith('/authorize', $this->sent[0]);
    }

    /** @throws Exception */
    public function testGetSubscriptionCannotEscapeItsPathSegment(): void
    {
        $api = $this->api();
        $api->subscription()->getSubscription('abc/../../oauth2/access_token');

        $this->assertStringNotContainsString('oauth2/access_token', rawurldecode('') . $this->sent[0]);
        $this->assertStringContainsString('%2F', $this->sent[0]);
    }

    /** @throws Exception */
    public function testOrdinaryIdentifiersAreUnharmed(): void
    {
        $uuid = '2a1b3c4d-0000-0000-0000-abcdefabcdef';
        $api = $this->api();
        $api->transaction()->getTransaction($uuid);

        $this->assertStringEndsWith('/v1/transaction/' . $uuid, $this->sent[0]);
    }
}
