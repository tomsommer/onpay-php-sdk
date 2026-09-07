<?php

declare(strict_types=1);

namespace OnPay\API\Acquirer;

/**
 * A payment provider available on the gateway, such as Klarna or ViaBill.
 */
class Provider
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
