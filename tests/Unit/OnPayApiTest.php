<?php

namespace Tests\Unit;

use OnPay\OAuth\Client\Http\CurlHttpClient;
use OnPay\OAuth\Client\Http\Request;
use OnPay\OAuth\Client\Http\Response;
use OnPay\OnPayAPI;
use OnPay\TokenStorageInterface;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
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

    public function testLogFailedResponseUsesErrorLogByDefault(): void {
        $tmpFile = tempnam(sys_get_temp_dir(), 'onpay_error_log_');
        $previousErrorLog = ini_get('error_log');
        $previousLogErrors = ini_get('log_errors');
        ini_set('error_log', $tmpFile);
        ini_set('log_errors', '1');

        try {
            $client = new CurlHttpClient();
            $request = new Request('GET', 'https://example.test/path');
            $response = new Response(500, 'failed-body');

            $method = (new \ReflectionClass($client))->getMethod('logFailedResponse');
            $method->setAccessible(true); // NOSONAR — reflection required to test protected logging path
            $method->invoke($client, $request, $response);

            $contents = file_get_contents($tmpFile);
            $this->assertStringContainsString('REQUEST=', $contents);
            $this->assertStringContainsString('RESPONSE=', $contents);
            $this->assertStringContainsString('failed-body', $contents);
        } finally {
            ini_set('error_log', $previousErrorLog);
            ini_set('log_errors', $previousLogErrors);
            @unlink($tmpFile);
        }
    }

    /** @throws Exception */
    public function testSetLoggerOnApiForwardsAndLoggerIsUsedForFailures(): void {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn('test_token');
        $api = new OnPayAPI($tokenStorage, ['client_id' => 'test_id', 'redirect_uri' => 'test_uri']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->equalTo('OnPay HTTP request failed'),
                $this->callback(function (array $context): bool {
                    return ($context['status'] ?? null) === 500
                        && false !== strpos((string) ($context['response'] ?? ''), 'failed-body');
                })
            );
        $api->setLogger($logger);

        $apiReflection = new \ReflectionClass($api);
        $httpClientProp = $apiReflection->getProperty('httpClient');
        $httpClientProp->setAccessible(true); // NOSONAR — reflection required to reach private collaborator
        /** @var CurlHttpClient $httpClient */
        $httpClient = $httpClientProp->getValue($api);

        $logMethod = (new \ReflectionClass($httpClient))->getMethod('logFailedResponse');
        $logMethod->setAccessible(true); // NOSONAR — reflection required to test protected logging path
        $logMethod->invoke($httpClient, new Request('GET', 'https://example.test/path'), new Response(500, 'failed-body'));
    }
}