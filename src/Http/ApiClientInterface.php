<?php

declare(strict_types=1);

namespace OnPay\Http;

use OnPay\API\Exception\ApiException;
use OnPay\API\Exception\ConnectionException;
use OnPay\API\Exception\TokenException;

/**
 * What the API service classes need in order to talk to OnPay: a way to make a
 * request, and the platform string that identifies the caller.
 *
 * One method rather than one per verb, because the set of verbs is OnPay's to
 * choose and not something this interface should have to keep up with.
 *
 * The exception contract is part of the interface, not an implementation
 * detail. The service classes let all three propagate, so a substitute
 * implementation has to raise the same ones for the same reasons.
 */
interface ApiClientInterface
{
    /**
     * @param string $method HTTP method, e.g. 'GET', 'POST', 'PATCH'
     * @param string $url Path below the API version prefix, e.g. 'transaction/1234'
     * @param mixed $body Encoded as JSON; null sends no body
     * @return mixed The decoded response body, or null when the body is empty
     *
     * @throws ApiException on a non-2xx response, or a body that is not JSON
     * @throws TokenException when the stored token is missing, unusable or rejected
     * @throws ConnectionException when the request never got an answer
     */
    public function request(string $method, string $url, mixed $body = null): mixed;

    /**
     * The platform identifier sent as the User-Agent.
     */
    public function getPlatform(): string;
}
