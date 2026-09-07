<?php

declare(strict_types=1);

namespace Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OnPay\API\GatewayService;
use OnPay\API\PaymentService;
use OnPay\API\SubscriptionService;
use OnPay\API\TransactionService;
use OnPay\Http\ApiClientInterface;
use OnPay\OnPayAPI;
use OnPay\StaticToken;
use OnPay\TokenStorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The facade's job is wiring: turning an option array into a provider, a token
 * manager and a client, and handing out services. Transport lives in
 * ApiClientTest, token handling in TokenManagerTest.
 */
class OnPayApiTest extends TestCase {
    /** @throws Exception */
    private function api(array $options = [], ?ResponseInterface $response = null, ?RequestInterface &$captured = null): OnPayAPI {
        $factory = new Psr17Factory();
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request) use (&$captured, $response): ResponseInterface {
                $captured = $request;
                return $response ?? new Response(200, ['content-type' => 'application/json'], '{}');
            }
        );

        return new OnPayAPI(
            new StaticToken('static_token'),
            array_merge(['client_id' => 'test_id'], $options),
            $httpClient,
            $factory,
            $factory
        );
    }

    /** @throws Exception */
    public function testInitializeThrowsOnMissingRequiredParameterClientId(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Required options not defined: client_id');
        new OnPayAPI($tokenStorage, ['redirect_uri' => 'test_uri']);
    }

    /** @throws Exception */
    public function testInitializeThrowsOnMissingRequiredParameterRedirectUri(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Required options not defined: redirect_uri');
        new OnPayAPI($tokenStorage, ['client_id' => 'test_id']);
    }

    public function testStaticTokenDoesNotRequireRedirectUri(): void {
        $this->expectNotToPerformAssertions();
        new OnPayAPI(new StaticToken('static_token'), ['client_id' => 'test_id']);
    }

    /** @throws Exception */
    public function testGatewayIdReachesTheProvider(): void {
        $api = $this->api(['gateway_id' => 'A5KM3QX7B']);
        $this->assertStringContainsString(
            '/A5KM3QX7B/oauth2/authorize',
            $api->getProvider()->getBaseAuthorizationUrl()
        );
    }

    /** @throws Exception */
    public function testLegacyNumericGatewayIdReachesTheProvider(): void {
        $api = $this->api(['gateway_id' => 1234]);
        $this->assertStringContainsString('/1234/oauth2/authorize', $api->getProvider()->getBaseAuthorizationUrl());
    }

    /** @throws Exception */
    #[DataProvider('invalidGatewayIdProvider')]
    public function testInvalidGatewayIdIsRejected(string $gatewayId): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('gatewayId must be a non-empty alphanumeric value');
        $this->api(['gateway_id' => $gatewayId]);
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

    /** @throws Exception */
    public function testBaseUrisReachTheProviderAndTheClient(): void {
        $api = $this->api([
            'base_uri' => 'https://api.test.onpay.io',
            'base_authorize_uri' => 'https://manage.test.onpay.io',
        ], null, $captured);
        $api->ping();

        $this->assertSame(
            'https://manage.test.onpay.io/oauth2/authorize',
            $api->getProvider()->getBaseAuthorizationUrl()
        );
        $this->assertSame('https://api.test.onpay.io/v1/ping', (string) $captured->getUri());
    }

    /** @throws Exception */
    public function testAuthorizeUrlContainsClientIdAndScope(): void {
        $url = $this->api()->authorize();
        $this->assertStringContainsString('client_id=test_id', $url);
        $this->assertStringContainsString('scope=full', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    /** @throws Exception */
    public function testPkceOptionReachesTheProvider(): void {
        $api = $this->api(['pkce_method' => 'S256']);
        $url = $api->authorize();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($api->getPkceCode());

        $api->setPkceCode('restored_verifier');
        $this->assertSame('restored_verifier', $api->getPkceCode());
    }

    /** @throws Exception */
    public function testPingGoesThroughTheApiClient(): void {
        $api = $this->api([], new Response(200, ['content-type' => 'application/json'], '{"ping":"pong"}'), $captured);

        $this->assertSame(['ping' => 'pong'], $api->ping());
        $this->assertSame('https://api.onpay.io/v1/ping', (string) $captured->getUri());
    }

    /** @throws Exception */
    public function testIsAuthorizedReflectsThePing(): void {
        $this->assertTrue($this->api([], new Response(200, ['content-type' => 'application/json'], '{}'))->isAuthorized());
        $this->assertFalse($this->api([], new Response(401, [], ''))->isAuthorized());
    }

    /** @throws Exception */
    public function testPlatformDefaultsToTheSdkVersion(): void {
        $this->assertSame('php-sdk/' . OnPayAPI::SDK_VERSION, $this->api()->getPlatform());
    }

    /** @throws Exception */
    public function testPlatformIsOverridable(): void {
        $api = $this->api(['platform' => 'woocommerce/1.2.3'], null, $captured);
        $api->ping();

        $this->assertSame('woocommerce/1.2.3', $api->getPlatform());
        $this->assertSame('woocommerce/1.2.3', $captured->getHeaderLine('User-Agent'));
    }

    /** @throws Exception */
    public function testServicesAreExposedAndMemoized(): void {
        $api = $this->api();

        $this->assertInstanceOf(TransactionService::class, $api->transaction());
        $this->assertInstanceOf(SubscriptionService::class, $api->subscription());
        $this->assertInstanceOf(PaymentService::class, $api->payment());
        $this->assertInstanceOf(GatewayService::class, $api->gateway());

        $this->assertSame($api->transaction(), $api->transaction());
        $this->assertSame($api->subscription(), $api->subscription());
        $this->assertSame($api->payment(), $api->payment());
        $this->assertSame($api->gateway(), $api->gateway());
    }

    /** @throws Exception */
    public function testApiClientIsExposedForUncoveredEndpoints(): void {
        $api = $this->api([], new Response(200, ['content-type' => 'application/json'], '{"ok":true}'), $captured);

        $this->assertInstanceOf(ApiClientInterface::class, $api->getApiClient());
        $this->assertSame(['ok' => true], $api->getApiClient()->get('some/new/endpoint'));
        $this->assertSame('https://api.onpay.io/v1/some/new/endpoint', (string) $captured->getUri());
    }
}
