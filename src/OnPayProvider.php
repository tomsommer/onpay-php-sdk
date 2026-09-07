<?php

declare(strict_types=1);

namespace OnPay;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;

/**
 * OAuth 2.0 provider for OnPay, built on league/oauth2-client.
 *
 * OnPay exposes no resource owner endpoint, which is why this extends
 * AbstractProvider rather than GenericProvider.
 */
class OnPayProvider extends AbstractProvider
{
    /** @var string */
    protected $urlAuthorize;

    /** @var string */
    protected $urlAccessToken;

    /**
     * PKCE code challenge method, or null to disable PKCE. Opt-in, because a
     * sessionless caller has to carry the verifier from getPkceCode() over to
     * setPkceCode() itself before exchanging the authorization code.
     *
     * @var string|null
     */
    protected $pkceMethod;

    /**
     * @return string
     */
    public function getBaseAuthorizationUrl()
    {
        return $this->urlAuthorize;
    }

    /**
     * @param array $params
     * @return string
     */
    public function getBaseAccessTokenUrl(array $params)
    {
        return $this->urlAccessToken;
    }

    /**
     * @param AccessToken $token
     * @return string
     */
    public function getResourceOwnerDetailsUrl(AccessToken $token)
    {
        throw new \BadMethodCallException('OnPay does not expose a resource owner endpoint');
    }

    /**
     * @return string[]
     */
    protected function getDefaultScopes()
    {
        return ['full'];
    }

    /**
     * @return string|null
     */
    protected function getPkceMethod()
    {
        return $this->pkceMethod;
    }

    /**
     * @return string
     */
    protected function getScopeSeparator()
    {
        return ' ';
    }

    /**
     * @param array|string $data
     * @return void
     * @throws IdentityProviderException
     */
    protected function checkResponse(ResponseInterface $response, $data)
    {
        if ($response->getStatusCode() < 400) {
            return;
        }

        $message = $response->getReasonPhrase();
        if (is_array($data)) {
            if (isset($data['errors'][0]['message'])) {
                $message = $data['errors'][0]['message'];
            } elseif (isset($data['error_description'])) {
                $message = $data['error_description'];
            } elseif (isset($data['error'])) {
                $message = $data['error'];
            }
        }

        throw new IdentityProviderException($message, $response->getStatusCode(), $data);
    }

    /**
     * @param array $response
     * @return ResourceOwnerInterface
     */
    protected function createResourceOwner(array $response, AccessToken $token)
    {
        throw new \BadMethodCallException('OnPay does not expose a resource owner endpoint');
    }
}
