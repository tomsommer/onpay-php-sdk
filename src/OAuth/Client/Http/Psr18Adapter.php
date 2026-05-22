<?php

namespace OnPay\OAuth\Client\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Adapts a PSR-18 ClientInterface (with PSR-17 factories for building requests)
 * to OnPay's internal HttpClientInterface, so the rest of the SDK can keep using
 * its existing Request/Response value objects regardless of the underlying client.
 */
class Psr18Adapter implements HttpClientInterface
{
    /** @var ClientInterface */
    private $client;

    /** @var RequestFactoryInterface */
    private $requestFactory;

    /** @var StreamFactoryInterface */
    private $streamFactory;

    public function __construct(
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory
    ) {
        $this->client = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    /**
     * @return Response
     */
    public function send(Request $request)
    {
        $psrRequest = $this->requestFactory->createRequest($request->getMethod(), $request->getUri());
        foreach ($request->getHeaders() as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        if (null !== $request->getBody()) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->getBody()));
        }

        $psrResponse = $this->client->sendRequest($psrRequest);

        $headers = [];
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return new Response(
            $psrResponse->getStatusCode(),
            (string) $psrResponse->getBody(),
            $headers
        );
    }
}
