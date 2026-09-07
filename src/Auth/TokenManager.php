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
    /**
     * How far ahead of the stated expiry a token is considered spent, to absorb
     * clock differences between us and OnPay.
     */
    private const EXPIRY_MARGIN_SECONDS = 30;

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
     * Forces a refresh of the stored token, whatever it claims about its expiry.
     *
     * Used when OnPay rejects a token the SDK believed was still good, which is
     * the only authority that actually counts.
     *
     * @throws TokenException when there is nothing to refresh with
     * @throws ConnectionException when the token endpoint could not be reached
     */
    public function forceRefresh(): AccessTokenInterface
    {
        $accessToken = $this->getAccessToken();
        $refreshToken = $accessToken?->getRefreshToken();
        if (null === $refreshToken) {
            throw new TokenException('Access token was rejected and no refresh token is available.');
        }

        return $this->refresh($refreshToken);
    }

    /**
     * Whether a rejected token could be renewed at all.
     */
    public function canRefresh(): bool
    {
        try {
            return null !== $this->getAccessToken()?->getRefreshToken();
        } catch (TokenException $e) {
            return false;
        }
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
        } catch (\InvalidArgumentException $e) {
            // A 2xx carrying no access_token, or a non-numeric expires_in, reaches
            // league's AccessToken constructor and fails there. That is still a
            // token problem, so it should not escape as an unrelated SPL type.
            throw new TokenException($e->getMessage(), $e->getCode(), $e);
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
        if (!is_array($values) || !isset($values['access_token']) || !is_string($values['access_token'])) {
            throw new TokenException('Stored token could not be read.');
        }

        // Legacy fkooman format carries issued_at + expires_in instead of expires.
        if (isset($values['issued_at'], $values['expires_in']) && !isset($values['expires'])) {
            $issuedAt = strtotime((string) $values['issued_at']);
            // An unparsable issued_at leaves no way to place the token in time.
            $values['expires'] = false !== $issuedAt
                ? $issuedAt + (int) $values['expires_in']
                : 1;
            unset($values['issued_at'], $values['provider_id']);
        }

        // expires_in is relative to when the token was issued, which a stored blob
        // no longer records. league would recompute it from the current time,
        // making the token perpetually fresh however old it really is. Dropping
        // it outright would be just as wrong in the other direction - a token
        // with no expiry at all - so treat it as spent and let it be renewed.
        if (isset($values['expires_in']) && !isset($values['expires'])) {
            $values['expires'] = 1;
        }
        unset($values['expires_in']);

        return new AccessToken($values);
    }

    /**
     * A token without an expiry - a static API token, for instance - never expires.
     */
    private function hasExpired(AccessTokenInterface $accessToken): bool
    {
        $expires = $accessToken->getExpires();

        // No expiry at all - a static API token, or league's own "0 means never".
        // Asking hasExpired() in that state raises a RuntimeException.
        if (null === $expires || 0 === $expires) {
            return false;
        }

        // Renew slightly early so a token that is technically alive but will be
        // dead by the time it reaches OnPay does not cost a round trip.
        return $expires < (time() + self::EXPIRY_MARGIN_SECONDS);
    }
}
