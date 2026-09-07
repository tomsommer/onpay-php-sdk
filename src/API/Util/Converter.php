<?php

declare(strict_types=1);

namespace OnPay\API\Util;


class Converter {
    private function __construct() {
    }

    /**
     * @param string|null $string
     * @return \DateTime|null
     */
    public static function toDateTimeFromString($string) {
        if (null === $string || '' === $string) {
            return null;
        }

        $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', (string) $string, new \DateTimeZone('UTC'));
        if (false !== $dateTime) {
            return $dateTime;
        }

        // Anything the documented format does not cover - an ISO-8601 stamp, say -
        // is worth a second attempt before giving up. Returning false here would
        // put a bool in a property the DTOs type as \DateTime.
        try {
            return new \DateTime((string) $string, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
    }
}
