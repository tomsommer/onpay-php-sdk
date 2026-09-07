<?php

declare(strict_types=1);

namespace Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OnPay\API\Exception\ApiException;
use OnPay\API\Exception\InvalidCartException;
use OnPay\API\Exception\InvalidFormatException;
use OnPay\API\Exception\MissingDataException;
use OnPay\API\PaymentWindow;
use OnPay\API\PaymentWindow\Cart;
use OnPay\API\PaymentWindow\CartItem;
use OnPay\API\PaymentWindow\PaymentInfo;
use OnPay\API\Util\Converter;
use OnPay\OnPayAPI;
use OnPay\StaticToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ServicesTest extends TestCase
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

    // ---- GatewayService ----

    /** @throws Exception */
    public function testGatewayInformation(): void
    {
        $api = $this->api('{"data":{"gateway_id":"A5KM3QX7B"}}');
        $information = $api->gateway()->getInformation();

        $this->assertSame('A5KM3QX7B', $information->gatewayId);
        $this->assertSame('https://api.onpay.io/v1/gateway/information', (string) $this->last->getUri());
    }

    /** @throws Exception */
    public function testGatewayInformationOnAMalformedBody(): void
    {
        $api = $this->api('{"nope":true}');

        $this->expectException(ApiException::class);
        $api->gateway()->getInformation();
    }

    // ---- PaymentService ----

    /** @throws Exception */
    public function testCreateNewPaymentPostsTheWindowFields(): void
    {
        $api = $this->api('{"data":{"uuid":"abc","status":"created"}}');

        $window = new PaymentWindow();
        $window->setSecret('s');
        $window->setGatewayId('A5KM3QX7B');
        $window->setCurrency('DKK');
        $window->setAmount('39999');
        $window->setReference('order-1');
        $window->setAcceptUrl('https://example.test/ok');
        $window->setWebsite('https://example.test/');

        $api->payment()->createNewPayment($window);

        $this->assertSame('https://api.onpay.io/v1/payment/create', (string) $this->last->getUri());
        $body = json_decode((string) $this->last->getBody(), true);
        $this->assertSame('DKK', $body['currency']);
        $this->assertSame('order-1', $body['reference']);
    }

    /** @throws Exception */
    public function testCreateNewPaymentRejectsAnIncompleteWindow(): void
    {
        $api = $this->api('{"data":{}}');

        $window = new PaymentWindow();
        $window->setSecret('s');
        $window->setCurrency('DKK');

        $this->expectException(MissingDataException::class);
        $api->payment()->createNewPayment($window);
    }

    // ---- PaymentInfo validation ----

    #[DataProvider('rejectedPaymentInfoProvider')]
    public function testPaymentInfoRejectsMalformedValues(string $setter, array $args): void
    {
        $info = new PaymentInfo();

        $this->expectException(InvalidFormatException::class);
        $info->{$setter}(...$args);
    }

    /** @return array<string, array{string, array<mixed>}> */
    public static function rejectedPaymentInfoProvider(): array
    {
        return [
            'mobile number with a plus' => ['setPhoneMobile', ['45', '+37123456']],
            'mobile number with a space' => ['setPhoneMobile', ['45', '371 234 56']],
            'country code too long' => ['setPhoneMobile', ['4512', '37123456']],
        ];
    }

    public function testPaymentInfoAcceptsPlainDigits(): void
    {
        $info = new PaymentInfo();
        $info->setPhoneMobile('45', '37123456');
        $info->setEmail('someone@example.test');

        $fields = $info->getFields();
        $this->assertSame('45', $fields['onpay_info_phone_mobile_cc']);
        $this->assertSame('someone@example.test', $fields['onpay_info_email']);
    }

    // ---- Cart ----

    public function testCartAcceptsPricesArrivedAtByArithmetic(): void
    {
        $cart = new Cart();
        $cart->setItems([new CartItem('item', 19.99 * 100, 1, 0, 'desc', 'sku')]);

        $this->expectNotToPerformAssertions();
        $cart->throwOnInvalid(1999);
    }

    public function testCartStillCatchesAGenuineMismatch(): void
    {
        $cart = new Cart();
        $cart->setItems([new CartItem('item', 1999, 1, 0, 'desc', 'sku')]);

        $this->expectException(InvalidCartException::class);
        $cart->throwOnInvalid(2999);
    }

    // ---- Converter ----

    public function testConverterParsesTheDocumentedFormat(): void
    {
        $this->assertSame(
            '2026-09-07 12:34:56',
            Converter::toDateTimeFromString('2026-09-07 12:34:56')?->format('Y-m-d H:i:s')
        );
    }

    public function testConverterFallsBackForIso8601(): void
    {
        $this->assertSame(
            '2026-09-07 12:00:00',
            Converter::toDateTimeFromString('2026-09-07T12:00:00+00:00')?->format('Y-m-d H:i:s')
        );
    }

    #[DataProvider('unparsableDateProvider')]
    public function testConverterReturnsNullRatherThanFalse(?string $input): void
    {
        $this->assertNull(Converter::toDateTimeFromString($input));
    }

    /** @return array<string, array{string|null}> */
    public static function unparsableDateProvider(): array
    {
        return ['gibberish' => ['not a date'], 'empty' => [''], 'null' => [null]];
    }
}
