<?php

declare(strict_types=1);

namespace OnPay\API\Gateway;

/**
 * A locale the payment window can be presented in.
 */
class PaymentWindowLanguage
{
    public ?string $locale;

    /**
     * @internal
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->locale = isset($data['locale']) ? (string) $data['locale'] : null;
    }
}
