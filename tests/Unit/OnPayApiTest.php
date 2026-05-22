<?php

namespace Tests\Unit;

use OnPay\OAuth\Client\Http\HttpClientInterface;
use OnPay\OAuth\Client\Http\Request;
use OnPay\OAuth\Client\Http\Response;
use OnPay\OnPayAPI;
use OnPay\TokenStorageInterface;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;

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
    public function testConstructorInjectsCustomHttpClient(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($this->buildToken('https://auth.test', 'test_id'));

        $sentRequests = [];
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('send')->willReturnCallback(function (Request $request) use (&$sentRequests) {
            $sentRequests[] = $request;
            return new Response(200, json_encode(['data' => ['pong' => 'merchant']]), ['Content-Type' => 'application/json']);
        });

        $api = new OnPayAPI(
            $tokenStorage,
            [
                'base_uri' => 'https://api.test',
                'base_authorize_uri' => 'https://auth.test',
                'client_id' => 'test_id',
                'redirect_uri' => 'test_uri',
            ],
            $httpClient
        );

        $this->assertTrue($api->isAuthorized());
        $this->assertCount(1, $sentRequests);
        $this->assertSame('GET', $sentRequests[0]->getMethod());
        $this->assertSame('https://api.test/v1/ping', $sentRequests[0]->getUri());
    }

    /** @throws Exception */
    public function testSetHttpClientReplacesClientAtRuntime(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($this->buildToken('https://auth.test', 'test_id'));

        $sentRequests = [];
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('send')->willReturnCallback(function (Request $request) use (&$sentRequests) {
            $sentRequests[] = $request;
            return new Response(200, json_encode(['data' => ['pong' => 'merchant']]), ['Content-Type' => 'application/json']);
        });

        $api = new OnPayAPI($tokenStorage, [
            'base_uri' => 'https://api.test',
            'base_authorize_uri' => 'https://auth.test',
            'client_id' => 'test_id',
            'redirect_uri' => 'test_uri',
        ]);
        $api->setHttpClient($httpClient);

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

        $api->setHttpClient($this->createMock(HttpClientInterface::class));
        $this->assertNull($clientProp->getValue($api)); // NOSONAR — reflection required to verify cache reset
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