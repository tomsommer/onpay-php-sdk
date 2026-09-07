<?php

declare(strict_types=1);

namespace OnPay\Http;

use OnPay\API\Exception\ApiException;
use OnPay\API\Exception\ConnectionException;
use OnPay\API\Exception\TokenException;
use OnPay\Auth\TokenManager;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * Sends authenticated requests to the OnPay API and turns the answers into
 * decoded values or typed exceptions.
 *
 * Holds no per-request state, so one instance can be shared for the life of an
 * application without a concurrent call disturbing another.
 */
class ApiClient implements ApiClientInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly TokenManager $tokenManager,
        private readonly string $baseUri,
        private readonly string $platform,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger;
    }

    public function get(string $url): mixed
    {
        return $this->send('GET', $url);
    }

    public function post(string $url, mixed $body = null): mixed
    {
        return $this->send('POST', $url, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    /**
     * @throws ApiException
     * @throws TokenException
     * @throws ConnectionException
     */
    private function send(string $method, string $url, ?string $body = null): mixed
    {
        $accessToken = $this->tokenManager->getValidAccessToken();
        $uri = $this->baseUri . '/v1/' . $url;

        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('User-Agent', $this->platform)
            ->withHeader('Authorization', 'Bearer ' . $accessToken->getToken());

        if (null !== $body) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionException($e->getMessage(), $e->getCode(), $e);
        }

        return $this->handleResponse(
            $response->getStatusCode(),
            (string) $response->getBody(),
            $response->getHeaderLine('content-type'),
            $method,
            $uri,
        );
    }

    /**
     * @throws ApiException
     * @throws TokenException
     */
    private function handleResponse(int $statusCode, string $body, string $contentType, string $method, string $uri): mixed
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            $decoded = $this->decodeBody($body, $statusCode);
            // Valid JSON that is not an object leaves every caller indexing into
            // a scalar. Reject it here so it arrives as an ApiException.
            if (null !== $decoded && !is_array($decoded)) {
                throw new ApiException('Unexpected API response: expected an object.', $statusCode);
            }

            return $decoded;
        }

        $message = '';
        if ('' !== $body && str_contains($contentType, 'application/json')) {
            $decoded = $this->decodeBody($body, $statusCode);
            // The message is only useful if it is a string; anything else would
            // otherwise reach the exception constructor and raise a TypeError.
            if (is_array($decoded) && isset($decoded['errors'][0]['message']) && is_string($decoded['errors'][0]['message'])) {
                $message = $decoded['errors'][0]['message'];
            }
        }

        $this->logFailedResponse($statusCode, $body, $method, $uri);

        if (401 === $statusCode || 403 === $statusCode) {
            throw new TokenException($message, $statusCode);
        }
        if (404 === $statusCode) {
            $message = 'Not found';
        }

        throw new ApiException($message, $statusCode);
    }

    /**
     * @throws ApiException
     */
    private function decodeBody(string $body, int $statusCode): mixed
    {
        if ('' === $body) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new ApiException('Failed to decode JSON body-response: ' . json_last_error_msg(), $statusCode);
        }

        return $decoded;
    }

    /**
     * Reports a failed response to the PSR-3 logger when one is set, and falls
     * back to error_log() so failures are never silently dropped.
     */
    private function logFailedResponse(int $statusCode, string $body, string $method, string $uri): void
    {
        if (null !== $this->logger) {
            $this->logger->warning('OnPay HTTP request failed', [
                'status' => $statusCode,
                'method' => $method,
                'uri' => $uri,
                'response' => $body,
            ]);

            return;
        }

        \error_log(sprintf('REQUEST=%s %s, RESPONSE=%d %s', $method, $uri, $statusCode, $body));
    }
}
