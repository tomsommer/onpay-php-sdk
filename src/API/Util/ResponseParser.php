<?php

declare(strict_types=1);

namespace OnPay\API\Util;

use OnPay\API\Exception\ApiException;

/**
 * Pulls the documented envelope out of a decoded API response.
 *
 * The service classes used to index into the decoded body directly, so a
 * response that was well-formed JSON but the wrong shape - an empty object, a
 * string where an object belongs - reached a DTO constructor and surfaced as a
 * TypeError. Callers catch ApiException, not TypeError, so a surprising
 * response became a fatal instead of a handled failure.
 *
 * @internal
 */
final class ResponseParser
{
    private function __construct()
    {
    }

    /**
     * The `data` member every single-object endpoint returns.
     *
     * @throws ApiException when the response does not carry one
     */
    public static function data(mixed $result): array
    {
        if (!is_array($result) || !isset($result['data']) || !is_array($result['data'])) {
            throw new ApiException('Unexpected API response: no data object.');
        }

        return $result['data'];
    }

    /**
     * The `data` member of a collection endpoint, which may legitimately be empty.
     *
     * @throws ApiException when the response does not carry one
     */
    public static function collection(mixed $result): array
    {
        if (!is_array($result) || !array_key_exists('data', $result) || !is_array($result['data'])) {
            throw new ApiException('Unexpected API response: no data collection.');
        }

        return $result['data'];
    }

    /**
     * HAL-style links. Absent links are not an error; the DTOs treat them as
     * optional, so an empty set is the honest answer.
     */
    public static function links(mixed $result): array
    {
        if (!is_array($result) || !isset($result['links']) || !is_array($result['links'])) {
            return [];
        }

        return $result['links'];
    }

    /**
     * @throws ApiException when a list response carries no pagination block
     */
    public static function pagination(mixed $result): array
    {
        if (!is_array($result) || !isset($result['meta']['pagination']) || !is_array($result['meta']['pagination'])) {
            throw new ApiException('Unexpected API response: no pagination metadata.');
        }

        return $result['meta']['pagination'];
    }
}
