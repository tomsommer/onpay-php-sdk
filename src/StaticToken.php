<?php

declare(strict_types=1);

namespace OnPay;

/**
 * This object is meant for use with static API tokens from OnPay.
 * In order to construct this object, a static API token created in in OnPay management panel is needed.
 *
 * The implementation is fairly simple and is used with OnPayAPI like this:
 *
 *      $tokenStorage = new StaticToken({STATIC_API_TOKEN});
 *      $onPayAPI = new OnPayAPI($tokenStorage, []);
 *
 *
 * Class StaticToken
 * @package OnPay
 */

class StaticToken implements TokenStorageInterface {
    public function __construct(
        protected string $staticToken,
    ) {
    }

    /**
     * Static tokens carry no expiry and no refresh token, so the SDK never
     * attempts to refresh them.
     */
    public function getToken(): string {
        return json_encode([
            'access_token' => $this->staticToken,
            'token_type' => 'Bearer',
            'scope' => 'full',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Dummy method, we do not need to save anything in this tokenstorage
     */
    public function saveToken(string $token): void {
    }
}
