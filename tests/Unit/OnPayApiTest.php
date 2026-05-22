<?php

namespace Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use OnPay\OnPayAPI;
use OnPay\TokenStorageInterface;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

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

    /** @throws Exception */
    public function testConstructorInjectsPsr18Client(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($this->buildToken('https://auth.test', 'test_id'));

        $factory = new Psr17Factory();
        $sentRequests = [];
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$sentRequests, $factory) {
            $sentRequests[] = $request;
            return $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['data' => ['pong' => 'merchant']])));
        });

        $api = new OnPayAPI(
            $tokenStorage,
            [
                'base_uri' => 'https://api.test',
                'base_authorize_uri' => 'https://auth.test',
                'client_id' => 'test_id',
                'redirect_uri' => 'test_uri',
            ],
            $httpClient,
            $factory,
            $factory
        );

        $this->assertTrue($api->isAuthorized());
        $this->assertCount(1, $sentRequests);
        $this->assertSame('GET', $sentRequests[0]->getMethod());
        $this->assertSame('https://api.test/v1/ping', (string) $sentRequests[0]->getUri());
    }

    /** @throws Exception */
    public function testSetHttpClientReplacesClientAtRuntime(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($this->buildToken('https://auth.test', 'test_id'));

        $factory = new Psr17Factory();
        $sentRequests = [];
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$sentRequests, $factory) {
            $sentRequests[] = $request;
            return $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['data' => ['pong' => 'merchant']])));
        });

        $api = new OnPayAPI($tokenStorage, [
            'base_uri' => 'https://api.test',
            'base_authorize_uri' => 'https://auth.test',
            'client_id' => 'test_id',
            'redirect_uri' => 'test_uri',
        ]);
        $api->setHttpClient($httpClient, $factory, $factory);

        $this->assertTrue($api->isAuthorized());
        $this->assertCount(1, $sentRequests);
    }

    /** @throws Exception */
    public function testSetHttpClientResetsCachedOAuthClient(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($this->buildToken('https://manage.onpay.io', 'test_id'));

        $api = new OnPayAPI($tokenStorage, ['client_id' => 'test_id', 'redirect_uri' => 'test_uri']);

        $reflection = new \ReflectionClass($api);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true); // NOSONAR — reflection required to inspect internal cache
        $getClient = $reflection->getMethod('getClient');
        $getClient->setAccessible(true); // NOSONAR — reflection required to force lazy init

        $getClient->invoke($api);
        $this->assertNotNull($clientProp->getValue($api)); // NOSONAR — reflection required to verify cache state

        $factory = new Psr17Factory();
        $api->setHttpClient($this->createMock(ClientInterface::class), $factory, $factory);
        $this->assertNull($clientProp->getValue($api)); // NOSONAR — reflection required to verify cache reset
    }

    /** @throws Exception */
    public function testSetHttpClientThrowsWhenFactoriesMissing(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn('test_token');

        $api = new OnPayAPI($tokenStorage, ['client_id' => 'test_id', 'redirect_uri' => 'test_uri']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PSR-17 RequestFactoryInterface and StreamFactoryInterface');
        $api->setHttpClient($this->createMock(ClientInterface::class));
    }

    private function buildToken(string $baseAuthUri, string $clientId): string {
        return json_encode([
            'provider_id' => $baseAuthUri . '/oauth2/authorize|' . $clientId,
            'issued_at' => date('Y-m-d H:i:s', time()),
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'full',
            'access_token' => 'test_access_token',
            'refresh_token' => 'test_refresh_token',
        ]);
    }
}
