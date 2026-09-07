<?php

declare(strict_types=1);

namespace OnPay\API\Transaction;

use OnPay\API\Util\Converter;

/**
 * One thing that happened to a transaction.
 *
 * @see https://onpay.io/docs/technical/api_v1.html
 */
class TransactionEvent
{
    public const ACTION_ACS = 'acs';
    public const ACTION_AMOUNT_CHANGE = 'amount-change';
    public const ACTION_AUTHORIZE = 'authorize';
    public const ACTION_CANCEL = 'cancel';
    public const ACTION_CAPTURE = 'capture';
    public const ACTION_CREATE = 'create';
    public const ACTION_REFUND = 'refund';
    public const ACTION_RENEW = 'renew';

    public ?string $uuid;

    /** The uuid of the transaction this happened to. */
    public ?string $transaction;

    public ?\DateTime $dateTime;

    /** One of the ACTION_* constants, though OnPay may add more. */
    public ?string $action;

    public ?bool $successful;

    /** Amount in minor units. */
    public ?int $amount;

    public ?string $resultCode;

    public ?string $resultText;

    public ?string $author;

    public ?string $ip;

    /** @var array<string, mixed> */
    public array $links;

    /**
     * @internal
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->uuid = isset($data['uuid']) ? (string) $data['uuid'] : null;
        $this->transaction = isset($data['transaction']) ? (string) $data['transaction'] : null;
        $this->dateTime = Converter::toDateTimeFromString($data['date_time'] ?? null);
        $this->action = isset($data['action']) ? (string) $data['action'] : null;
        $this->successful = isset($data['successful']) ? (bool) $data['successful'] : null;
        $this->amount = isset($data['amount']) ? (int) $data['amount'] : null;
        $this->resultCode = isset($data['result_code']) ? (string) $data['result_code'] : null;
        $this->resultText = isset($data['result_text']) ? (string) $data['result_text'] : null;
        $this->author = isset($data['author']) ? (string) $data['author'] : null;
        $this->ip = isset($data['ip']) ? (string) $data['ip'] : null;
        $this->links = isset($data['links']) && is_array($data['links']) ? $data['links'] : [];
    }
}
