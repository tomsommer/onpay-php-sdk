<?php

declare(strict_types=1);

namespace Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OnPay\API\Exception\ApiException;
use OnPay\API\Transaction\TransactionEvent;
use OnPay\OnPayAPI;
use OnPay\StaticToken;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class NewEndpointsTest extends TestCase
{
    private ?RequestInterface $last = null;

    /** @throws Exception */
    private function api(string $body, int $status = 200): OnPayAPI
    {
        $factory = new Psr17Factory();
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request) use ($body, $status): ResponseInterface {
                $this->last = $request;
                return new Response($status, ['content-type' => 'application/json'], $body);
            }
        );

        return new OnPayAPI(new StaticToken('t'), ['client_id' => 'x'], $client, $factory, $factory);
    }

    // ---- GET /v1/transaction/events/ ----

    /** @throws Exception */
    public function testTransactionEvents(): void
    {
        $api = $this->api((string) json_encode([
            'data' => [[
                'uuid' => 'e-1',
                'transaction' => 't-1',
                'date_time' => '2026-09-07 12:34:56',
                'action' => 'capture',
                'successful' => true,
                'amount' => 39999,
                'result_code' => '0',
                'result_text' => 'OK',
                'author' => 'someone',
                'ip' => '203.0.113.4',
                'links' => ['transaction' => 'https://api.onpay.io/v1/transaction/t-1'],
            ]],
            'meta' => ['next_cursor' => 'CURSOR2'],
        ]));

        $events = $api->transaction()->getEvents();

        $this->assertSame('https://api.onpay.io/v1/transaction/events/', (string) $this->last->getUri());
        $this->assertCount(1, $events->events);
        $this->assertTrue($events->hasMore());
        $this->assertSame('CURSOR2', $events->nextCursor);

        $event = $events->events[0];
        $this->assertInstanceOf(TransactionEvent::class, $event);
        $this->assertSame('e-1', $event->uuid);
        $this->assertSame(TransactionEvent::ACTION_CAPTURE, $event->action);
        $this->assertTrue($event->successful);
        $this->assertSame(39999, $event->amount);
        $this->assertSame('2026-09-07 12:34:56', $event->dateTime?->format('Y-m-d H:i:s'));
        $this->assertSame('https://api.onpay.io/v1/transaction/t-1', $event->links['transaction']);
    }

    /** @throws Exception */
    public function testTransactionEventsWithACursor(): void
    {
        $api = $this->api('{"data":[],"meta":{"next_cursor":""}}');
        $events = $api->transaction()->getEvents('CURSOR2');

        $this->assertStringEndsWith('/transaction/events/?cursor=CURSOR2', (string) $this->last->getUri());
        $this->assertFalse($events->hasMore(), 'an empty cursor means nothing further for now');
        $this->assertNull($events->nextCursor);
    }

    /** @throws Exception */
    public function testTransactionEventsCursorIsEncoded(): void
    {
        $api = $this->api('{"data":[]}');
        $api->transaction()->getEvents('a b&c=d');

        $this->assertStringContainsString('cursor=a+b%26c%3Dd', (string) $this->last->getUri());
    }

    // ---- GET /v1/gateway/window/v3/language/ ----

    /** @throws Exception */
    public function testPaymentWindowLanguages(): void
    {
        $api = $this->api('{"data":[{"locale":"da"},{"locale":"en"}]}');
        $languages = $api->gateway()->getPaymentWindowLanguages();

        $this->assertSame('https://api.onpay.io/v1/gateway/window/v3/language/', (string) $this->last->getUri());
        $this->assertSame(['da', 'en'], array_map(static fn ($l) => $l->locale, $languages));
    }

    // ---- GET /v1/acquirer ----

    /** @throws Exception */
    public function testAcquirers(): void
    {
        $api = $this->api('{"data":[{"name":"nets","active":true,"links":{"self":"https://api.onpay.io/v1/acquirer/nets"}}]}');
        $acquirers = $api->acquirer()->getAcquirers();

        $this->assertSame('https://api.onpay.io/v1/acquirer', (string) $this->last->getUri());
        $this->assertCount(1, $acquirers);
        $this->assertSame('nets', $acquirers[0]->name);
        $this->assertTrue($acquirers[0]->active);
    }

    /**
     * Acquirer-specific fields differ per acquirer, so they are kept as given
     * rather than flattened into properties that would be null for most.
     *
     * @throws Exception
     */
    public function testDetailedAcquirerKeepsItsAcquirerSpecificFields(): void
    {
        $api = $this->api((string) json_encode(['data' => [
            'name' => 'nets',
            'active' => true,
            'links' => ['self' => 'https://api.onpay.io/v1/acquirer/nets'],
            'exemptions' => ['sca_low_value' => true],
            'mcc' => '5734',
            'visa_bin' => '123456',
            'sca_mode' => 'default',
        ]]));

        $acquirer = $api->acquirer()->getAcquirer('nets');

        $this->assertSame('https://api.onpay.io/v1/acquirer/nets', (string) $this->last->getUri());
        $this->assertSame('nets', $acquirer->name);
        $this->assertSame('5734', $acquirer->getSetting('mcc'));
        $this->assertSame('123456', $acquirer->getSetting('visa_bin'));
        $this->assertTrue($acquirer->hasLowValueScaExemption());
        $this->assertArrayNotHasKey('name', $acquirer->settings);
    }

    /** @throws Exception */
    public function testAcquirerWithoutExemptionsReportsUnknown(): void
    {
        $api = $this->api('{"data":{"name":"swedbank","active":false}}');
        $this->assertNull($api->acquirer()->getAcquirer('swedbank')->hasLowValueScaExemption());
    }

    // ---- PATCH /v1/acquirer/{name} ----

    /** @throws Exception */
    public function testUpdateAcquirerSendsAPatchWithTheDataEnvelope(): void
    {
        $api = $this->api('{"data":{"name":"nets","active":false,"mcc":"5734"}}', 202);
        $acquirer = $api->acquirer()->updateAcquirer('nets', ['active' => false, 'mcc' => '5734']);

        $this->assertSame('PATCH', $this->last->getMethod());
        $this->assertSame('https://api.onpay.io/v1/acquirer/nets', (string) $this->last->getUri());
        $this->assertSame(
            ['data' => ['active' => false, 'mcc' => '5734']],
            json_decode((string) $this->last->getBody(), true)
        );
        $this->assertFalse($acquirer->active);
    }

    /** @throws Exception */
    public function testUpdateAcquirerRejectsAnEmptyChangeSet(): void
    {
        $api = $this->api('{"data":{}}');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('No settings to update');
        $api->acquirer()->updateAcquirer('nets', []);
    }

    /** @throws Exception */
    public function testAcquirerNameIsEncoded(): void
    {
        $api = $this->api('{"data":{"name":"x"}}');
        $api->acquirer()->getAcquirer('nets/../provider?');

        $this->assertStringNotContainsString('/provider?', (string) $this->last->getUri());
        $this->assertStringContainsString('%2F', (string) $this->last->getUri());
    }

    // ---- GET /v1/provider and GET /v1/wallet ----

    /** @throws Exception */
    public function testProviders(): void
    {
        $api = $this->api('{"data":[{"name":"Klarna","active":true},{"name":"ViaBill","active":false}]}');
        $providers = $api->acquirer()->getProviders();

        $this->assertSame('https://api.onpay.io/v1/provider', (string) $this->last->getUri());
        $this->assertSame(['Klarna', 'ViaBill'], array_map(static fn ($p) => $p->name, $providers));
        $this->assertFalse($providers[1]->active);
    }

    /** @throws Exception */
    public function testWallets(): void
    {
        $api = $this->api('{"data":[{"name":"applepay","active":true},{"name":"googlepay","active":true}]}');
        $wallets = $api->acquirer()->getWallets();

        $this->assertSame('https://api.onpay.io/v1/wallet', (string) $this->last->getUri());
        $this->assertSame(['applepay', 'googlepay'], array_map(static fn ($w) => $w->name, $wallets));
    }

    /** @throws Exception */
    public function testMalformedCollectionsRaiseApiException(): void
    {
        $api = $this->api('{"nope":true}');

        $this->expectException(ApiException::class);
        $api->acquirer()->getWallets();
    }
}
