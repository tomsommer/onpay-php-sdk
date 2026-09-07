<?php

namespace OnPay\API\Http;

class Response {
    /**
     * @var int $statusCode
     */
    protected $statusCode;

    /**
     * @var string $body
     */
    protected $body;

    /**
     * @return int
     */
    public function getStatusCode() {
        return $this->statusCode;
    }

    /**
     * @param int $statusCode
     */
    public function setStatusCode($statusCode) {
        $this->statusCode = $statusCode;
    }

    /**
     * @return string
     */
    public function getBody() {
        return $this->body;
    }

    /**
     * @param string $body
     */
    public function setBody($body) {
        $this->body = $body;
    }
}
