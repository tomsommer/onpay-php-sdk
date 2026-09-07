<?php

declare(strict_types=1);

namespace OnPay\API\Payment;

use OnPay\API\Exception\ApiException;
use OnPay\API\Util\ResponseParser;

class SimplePayment {

    private $uuid;
    private $amount;
    private $currency;
    private $expiration;
    private $language;
    private $method;
    private $paymentLink;

    /**
     * @throws ApiException when the response carries no data object
     */
    public function __construct($response) {
        $data = ResponseParser::data($response);
        $links = ResponseParser::links($response);

        $this->uuid = $data['payment_uuid'] ?? null;
        $this->amount = $data['amount'] ?? null;
        $this->currency = $data['currency_code'] ?? null;
        $this->expiration = $data['expiration'] ?? null;
        $this->language = $data['language'] ?? null;
        $this->method = $data['method'] ?? null;
        $this->paymentLink = $links['payment_window'] ?? null;
    }

    public function getUuid() {
        return $this->uuid;
    }

    public function getAmount() {
        return $this->amount;
    }

    public function getCurrency() {
        return $this->currency;
    }

    public function getExpiration() {
        return $this->expiration;
    }

    public function getLanguage() {
        return $this->language;
    }

    public function getMethod() {
        return $this->method;
    }

    public function getPaymentWindowLink() {
        return $this->paymentLink;
    }

}
