<?php

declare(strict_types=1);

namespace OnPay\API;

use OnPay\API\Acquirer\Acquirer;
use OnPay\API\Acquirer\Provider;
use OnPay\API\Acquirer\Wallet;
use OnPay\API\Util\ResponseParser;
use OnPay\Http\ApiClientInterface;

/**
 * The acquirers, providers and wallets configured on the gateway.
 */
class AcquirerService
{
    public function __construct(
        private readonly ApiClientInterface $api,
    ) {
    }

    /**
     * @return Acquirer[]
     * @throws Exception\ApiException
     * @throws Exception\ConnectionException
     * @throws Exception\TokenException
     */
    public function getAcquirers(): array
    {
        $result = $this->api->request('GET', 'acquirer');

        return array_map(
            static fn (array $item): Acquirer => new Acquirer($item),
            ResponseParser::collection($result)
        );
    }

    /**
     * @param string $name The acquirer's name, e.g. 'nets' or 'clearhaus'
     * @throws Exception\ApiException
     * @throws Exception\ConnectionException
     * @throws Exception\TokenException
     */
    public function getAcquirer(string $name): Acquirer
    {
        if ('' === $name) {
            throw new Exception\ApiException('Acquirer name must be provided');
        }

        return new Acquirer(ResponseParser::data($this->api->request('GET', 'acquirer/' . rawurlencode($name))));
    }

    /**
     * Updates an acquirer's settings.
     *
     * Which fields are writable depends on the acquirer, so this takes them as
     * given rather than enumerating a set that would be wrong for most of them.
     * OnPay answers with the updated acquirer.
     *
     * @param string $name The acquirer's name, e.g. 'nets' or 'clearhaus'
     * @param array<string, mixed> $settings e.g. ['active' => true, 'mcc' => '5734']
     * @throws Exception\ApiException
     * @throws Exception\ConnectionException
     * @throws Exception\TokenException
     */
    public function updateAcquirer(string $name, array $settings): Acquirer
    {
        if ('' === $name) {
            throw new Exception\ApiException('Acquirer name must be provided');
        }
        if ([] === $settings) {
            throw new Exception\ApiException('No settings to update were provided');
        }

        $result = $this->api->request('PATCH', 'acquirer/' . rawurlencode($name), ['data' => $settings]);

        return new Acquirer(ResponseParser::data($result));
    }

    /**
     * @return Provider[]
     * @throws Exception\ApiException
     * @throws Exception\ConnectionException
     * @throws Exception\TokenException
     */
    public function getProviders(): array
    {
        $result = $this->api->request('GET', 'provider');

        return array_map(
            static fn (array $item): Provider => new Provider($item),
            ResponseParser::collection($result)
        );
    }

    /**
     * @return Wallet[]
     * @throws Exception\ApiException
     * @throws Exception\ConnectionException
     * @throws Exception\TokenException
     */
    public function getWallets(): array
    {
        $result = $this->api->request('GET', 'wallet');

        return array_map(
            static fn (array $item): Wallet => new Wallet($item),
            ResponseParser::collection($result)
        );
    }
}
