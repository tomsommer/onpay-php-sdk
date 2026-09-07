<?php

declare(strict_types=1);

namespace OnPay\API\Acquirer;

/**
 * A wallet available on the gateway, such as applepay or mobilepay.
 */
class Wallet
{
    public ?string $name;

    public ?bool $active;

    /**
     * @internal
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->name = isset($data['name']) ? (string) $data['name'] : null;
        $this->active = isset($data['active']) ? (bool) $data['active'] : null;
    }
}
