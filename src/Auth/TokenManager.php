<?php

declare(strict_types=1);

namespace OnPay\Auth;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use League\OAuth2\Client\Token\SettableRefreshTokenInterface;
use OnPay\API\Exception\ConnectionException;
use OnPay\API\Exception\TokenException;
use OnPay\TokenStorageInterface;
use TomSommer\OAuth2\Client\Provider\OnPay as OnPayProvider;

/**
 * Owns the access token: reading it out of storage, deciding whether it is
 * still usable, refreshing it, and writing it back.
 *
 * Every write to storage goes through here, so behaviour that has to apply to
 * all of them - carrying a refresh token across a refresh, for instance - has
 * one place to live.
 */
class TokenManager
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly OnPayProvider $provider,
    ) {
    }

    /**
     * Returns a token usable right now, refreshing first if the stored one has
     * expired.
     *
     * @throws TokenException when there is no token, it cannot be read, or it
     *                        expired with no way to renew it
     * @throws ConnectionException when the token endpoint could not be reached
     */
    public function getValidAccessToken(): AccessTokenInterface
    {
        $accessToken = $this->getAccessToken();
        if (null === $accessToken) {
            throw new TokenException('No access token stored. Possible invalid token.');
        }

        if (!$this->hasExpired($accessToken)) {
            return $accessToken;
        }

        $refreshToken = $accessToken->getRefreshToken();
        if (null === $refreshToken) {
            throw new TokenException('Access token has expired and no refresh token is available.');
        }

        return $this->refresh($refreshToken);
    }

    /**
     * Exchanges an authorization code for a token and stores it.
     *
     * @throws TokenException when the code was rejected
     * @throws ConnectionException when the token endpoint could not be reached
     */
    public function exchangeAuthorizationCode(string $code): AccessTokenInterface
    {
        return $this->store($this->grant('authorization_code', ['code' => $code]));
    }

    /**
     * @throws TokenException
     * @throws ConnectionException
     */
    private function refresh(string $refreshToken): AccessTokenInterface
    {
        $refreshed = $this->grant('refresh_token', ['refresh_token' => $refreshToken]);

        // RFC 6749 section 6 lets the server leave refresh_token out of a refresh
        // response, meaning the old one stays valid. Storing the response as-is
        // would drop it and make the next expiry unrecoverable.
        if (null === $refreshed->getRefreshToken() && $refreshed instanceof SettableRefreshTokenInterface) {
            $refreshed->setRefreshToken($refreshToken);
        }

        return $this->store($refreshed);
    }

    /**
     * @param array<string, mixed> $options
     * @throws TokenException
     * @throws ConnectionException
     */
    private function grant(string $grant, array $options): AccessTokenInterface
    {
        try {
            return $this->provider->getAccessToken($grant, $options);
        } catch (IdentityProviderException $e) {
            throw new TokenException($e->getMessage(), $e->getCode(), $e);
        } catch (\UnexpectedValueException $e) {
            throw new ConnectionException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function store(AccessTokenInterface $accessToken): AccessTokenInterface
    {
        $this->tokenStorage->saveToken(json_encode($accessToken, JSON_THROW_ON_ERROR));

        return $accessToken;
    }

    /**
     * Reads the stored token. Accepts both the league/oauth2-client format this
     * SDK writes, and the legacy fkooman/oauth2-client format written by SDK
     * versions 1.x, so stored tokens survive the upgrade.
     *
     * @throws TokenException when a token is stored but cannot be read
     */
    private function getAccessToken(): ?AccessTokenInterface
    {
        $json = $this->tokenStorage->getToken();

        if (null === $json || '' === $json) {
            return null;
        }

        $values = json_decode($json, true);
        if (!is_array($values) || !isset($values['access_token'])) {
            throw new TokenException('Stored token could not be read.');
        }

        // Legacy fkooman format carries issued_at + expires_in instead of expires.
        if (isset($values['issued_at'], $values['expires_in']) && !isset($values['expires'])) {
            $issuedAt = strtotime((string) $values['issued_at']);
            if (false !== $issuedAt) {
                $values['expires'] = $issuedAt + (int) $values['expires_in'];
            }
            unset($values['issued_at'], $values['expires_in'], $values['provider_id']);
        }

        return new AccessToken($values);
    }

    /**
     * A token without an expiry - a static API token, for instance - never expires.
     */
    private function hasExpired(AccessTokenInterface $accessToken): bool
    {
        return null !== $accessToken->getExpires() && $accessToken->hasExpired();
    }
}
