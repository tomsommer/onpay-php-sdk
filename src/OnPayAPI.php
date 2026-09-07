<?php

namespace OnPay;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use OnPay\API\Exception\ApiException;
use OnPay\API\Exception\ConnectionException;
use OnPay\API\Exception\TokenException;
use OnPay\API\GatewayService;
use OnPay\API\Http\Request as HttpRequest;
use OnPay\API\Http\Response as HttpResponse;
use OnPay\API\PaymentService;
use OnPay\API\SubscriptionService;
use OnPay\API\TransactionService;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class OnPayAPI implements LoggerAwareInterface {
    use LoggerAwareTrait;

    const SDK_VERSION = '2.0.0';

    protected TokenStorageInterface $tokenStorage;

    protected array $options = [];

    protected OnPayProvider $oauth2Provider;

    protected ?TransactionService $transactionService = null;

    protected ?SubscriptionService $subscriptionService = null;

    protected ?PaymentService $paymentService = null;

    protected ?GatewayService $gatewayService = null;

    protected string $scope = 'full';

    protected ?HttpRequest $request = null;

    protected ?HttpResponse $response = null;

    protected ClientInterface $httpClient;

    protected RequestFactoryInterface $requestFactory;

    protected StreamFactoryInterface $streamFactory;

    protected string $platform;

    /**
     * OnPayAPI constructor.
     *
     * The optional PSR-18 $httpClient (with PSR-17 $requestFactory and $streamFactory)
     * routes all API requests through any PSR-18 compatible HTTP client: Symfony
     * HttpClient, Guzzle, Buzz and so on. All parameters are typed so DI containers
     * can autowire registered services automatically. When they are omitted, the
     * client and factories are auto-discovered from the installed packages.
     *
     * @param TokenStorageInterface $tokenStorage
     * @param array $options
     * @param ClientInterface|null $httpClient PSR-18 HTTP client
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface|null $streamFactory PSR-17 stream factory
     * @param LoggerInterface|null $logger PSR-3 logger for failed responses
     */
    public function __construct(
        TokenStorageInterface $tokenStorage,
        array $options,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null
    ) {
        $this->tokenStorage = $tokenStorage;

        $defaultOptions = [
            'base_uri' => 'https://api.onpay.io',
            'base_authorize_uri' => 'https://manage.onpay.io',
        ];

        $requiredOptions = $this->getRequiredOptions($tokenStorage);

        $missing = array_diff_key(array_flip($requiredOptions), $options);
        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                'Required options not defined: ' . implode(', ', array_keys($missing))
            );
        }

        $this->options = array_merge($defaultOptions, $options);

        if (isset($this->options['gateway_id'])) {
            $gatewayId = (string) $this->options['gateway_id'];
            if ($gatewayId === '' || !preg_match('/^[A-Z0-9]+$/', $gatewayId)) {
                throw new \InvalidArgumentException('gateway_id must be a non-empty alphanumeric value');
            }
            $authUrl = $this->options['base_authorize_uri'] . '/' . $gatewayId . '/oauth2/authorize';
        } else {
            $authUrl = $this->options['base_authorize_uri'] . '/oauth2/authorize';
        }

        // Set redirect_uri to an empty value if none is sent
        if (!array_key_exists('redirect_uri', $this->options)) {
            $this->options['redirect_uri'] = '';
        }

        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->logger = $logger;

        $this->oauth2Provider = new OnPayProvider(
            [
                'clientId' => $this->options['client_id'],
                'redirectUri' => $this->options['redirect_uri'],
                'urlAuthorize' => $authUrl,
                'urlAccessToken' => $this->options['base_uri'] . '/oauth2/access_token',
                'scopes' => [$this->scope],
                'pkceMethod' => $this->options['pkce_method'] ?? null,
            ],
            $this->getProviderCollaborators()
        );

        if (array_key_exists('platform', $this->options)) {
            $this->platform = $this->options['platform'];
        } else {
            $this->platform = 'php-sdk' . '/' . self::SDK_VERSION;
        }
    }

    /**
     * Replaces the PSR-18 HTTP client used for API requests at runtime.
     *
     * @param ClientInterface $httpClient
     * @param RequestFactoryInterface|null $requestFactory
     * @param StreamFactoryInterface|null $streamFactory
     * @return void
     */
    public function setHttpClient(
        ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null
    ): void {
        $this->httpClient = $httpClient;
        if (null !== $requestFactory) {
            $this->requestFactory = $requestFactory;
        }
        if (null !== $streamFactory) {
            $this->streamFactory = $streamFactory;
        }
    }

    /**
     * Returns the OAuth 2.0 provider, so consumers can drive the authorization
     * flow themselves when the convenience methods are not enough.
     */
    public function getProvider(): OnPayProvider {
        return $this->oauth2Provider;
    }

    /**
     * The PKCE code verifier generated by the last authorize() call. Only set
     * when the 'pkce_method' option is in use. Persist it alongside the OAuth
     * state and hand it back with setPkceCode() before finishAuthorize().
     */
    public function getPkceCode(): ?string {
        return $this->oauth2Provider->getPkceCode();
    }

    /**
     * Restores the PKCE code verifier obtained from getPkceCode().
     */
    public function setPkceCode(string $pkceCode): void {
        $this->oauth2Provider->setPkceCode($pkceCode);
    }

    /**
     * Checks if we have a Token that looks valid.
     * If it looks valid, we'll attempt to ping the API.
     *
     * @return bool
     */
    public function isAuthorized(): bool {
        // If we're able to ping the API, we're authorized.
        try {
            $this->ping();
            return true;
        } catch (TokenException $e) {
            return false;
        }
    }

    /**
     * Returns the platform value set.
     * @return string
     */
    public function getPlatform(): string {
        return $this->platform;
    }

    /**
     * Returns a URL the user should be redirected to, for authorizing.
     *
     * @return string
     */
    public function authorize(): string {
        return $this->oauth2Provider->getAuthorizationUrl();
    }

    /**
     * Exchanges an authorization code for an access token and stores it.
     *
     * @param string $code
     * @return void
     * @throws TokenException
     * @throws ConnectionException
     */
    public function finishAuthorize(string $code): void {
        try {
            $accessToken = $this->oauth2Provider->getAccessToken('authorization_code', [
                'code' => $code,
            ]);
        } catch (IdentityProviderException $e) {
            throw new TokenException($e->getMessage(), $e->getCode(), $e);
        } catch (\UnexpectedValueException $e) {
            throw new ConnectionException($e->getMessage(), $e->getCode(), $e);
        }

        $this->tokenStorage->saveToken(json_encode($accessToken));
    }

    /**
     * Simple method that just checks if API requests can be made
     *
     * @return mixed
     * @throws ApiException
     * @throws TokenException
     * @throws ConnectionException
     */
    public function ping(): mixed {
        return $this->get('ping');
    }

    /**
     * @internal
     * @param string $url
     * @return mixed
     * @throws ApiException
     * @throws TokenException
     * @throws ConnectionException
     */
    public function get(string $url): mixed {
        return $this->send('GET', $url);
    }

    /**
     * @internal
     * @param string $url
     * @param mixed $postBody
     * @return mixed
     * @throws ApiException
     * @throws TokenException
     * @throws ConnectionException
     */
    public function post(string $url, mixed $postBody = null): mixed {
        return $this->send('POST', $url, json_encode($postBody, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param string $method
     * @param string $url
     * @param string|null $body
     * @return mixed
     * @throws ApiException
     * @throws TokenException
     * @throws ConnectionException
     */
    private function send(string $method, string $url, ?string $body = null): mixed {
        $accessToken = $this->getValidAccessToken();
        $uri = $this->options['base_uri'] . '/v1/' . $url;

        $headers = [
            'User-Agent' => $this->platform,
            'Authorization' => 'Bearer ' . $accessToken->getToken(),
        ];
        if (null !== $body) {
            $headers['Content-Type'] = 'application/json';
        }

        $request = $this->requestFactory->createRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (null !== $body) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        $this->setLastHttpRequest($method, $uri, $headers, null === $body ? '' : $body);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionException($e->getMessage(), $e->getCode(), $e);
        }

        $statusCode = $response->getStatusCode();
        $responseBody = (string) $response->getBody();
        $this->setLastHttpResponse($statusCode, $responseBody);

        return $this->handleResponse($statusCode, $responseBody, $response->getHeaderLine('content-type'));
    }

    /**
     * Returns a usable access token, refreshing it first when it has expired.
     *
     * @return AccessTokenInterface
     * @throws TokenException
     * @throws ConnectionException
     */
    private function getValidAccessToken(): AccessTokenInterface {
        $accessToken = $this->getAccessToken();
        if (null === $accessToken) {
            throw new TokenException('No access token stored. Possible invalid token.');
        }

        if (!$this->hasExpired($accessToken)) {
            return $accessToken;
        }

        if (null === $accessToken->getRefreshToken()) {
            throw new TokenException('Access token has expired and no refresh token is available.');
        }

        try {
            $accessToken = $this->oauth2Provider->getAccessToken('refresh_token', [
                'refresh_token' => $accessToken->getRefreshToken(),
            ]);
        } catch (IdentityProviderException $e) {
            throw new TokenException($e->getMessage(), $e->getCode(), $e);
        } catch (\UnexpectedValueException $e) {
            throw new ConnectionException($e->getMessage(), $e->getCode(), $e);
        }

        $this->tokenStorage->saveToken(json_encode($accessToken));

        return $accessToken;
    }

    /**
     * Reads the stored token. Accepts both the league/oauth2-client format this
     * SDK writes, and the legacy fkooman/oauth2-client format written by SDK
     * versions 1.x, so stored tokens survive the upgrade.
     *
     * @return AccessTokenInterface|null
     * @throws TokenException
     */
    private function getAccessToken(): ?AccessTokenInterface {
        $json = $this->tokenStorage->getToken();

        if (null === $json || '' === $json) {
            return null;
        }

        $values = json_decode($json, true);
        if (!is_array($values) || !isset($values['access_token'])) {
            throw new TokenException('Stored token could not be read.');
        }

        // Legacy fkooman format carries issued_at + expires_in instead of expires.
        if (isset($values['issued_at']) && isset($values['expires_in']) && !isset($values['expires'])) {
            $issuedAt = strtotime($values['issued_at']);
            if (false !== $issuedAt) {
                $values['expires'] = $issuedAt + (int) $values['expires_in'];
            }
            unset($values['issued_at'], $values['expires_in'], $values['provider_id']);
        }

        return new AccessToken($values);
    }

    /**
     * A token without an expiry (a static API token, for instance) never expires.
     *
     * @return bool
     */
    private function hasExpired(AccessTokenInterface $accessToken): bool {
        return null !== $accessToken->getExpires() && $accessToken->hasExpired();
    }

    /**
     * @param TokenStorageInterface $tokenStorage
     * @return string[]
     */
    private function getRequiredOptions(TokenStorageInterface $tokenStorage): array {
        $options = [
            'client_id'
        ];

        if (!$tokenStorage instanceof StaticToken) {
            // Redirect URI is not needed for static tokens
            $options[] = 'redirect_uri';
        }

        return $options;
    }

    /**
     * league/oauth2-client talks to the token endpoint through Guzzle. When the
     * injected PSR-18 client happens to be Guzzle, hand it over so both paths
     * share one client; otherwise let the provider build its own.
     *
     * @return array
     */
    private function getProviderCollaborators(): array {
        if (
            interface_exists('GuzzleHttp\ClientInterface')
            && $this->httpClient instanceof \GuzzleHttp\ClientInterface
        ) {
            return ['httpClient' => $this->httpClient];
        }

        return [];
    }

    /**
     * @param int $statusCode
     * @param string $body
     * @param string $contentType
     * @return mixed
     * @throws ApiException
     * @throws TokenException
     */
    private function handleResponse(int $statusCode, string $body, string $contentType): mixed {
        if ($statusCode >= 200 && $statusCode < 300) {
            return $this->decodeBody($body, $statusCode);
        }

        $message = '';
        if ('' !== $body && false !== strpos($contentType, 'application/json')) {
            $decoded = $this->decodeBody($body, $statusCode);
            if (is_array($decoded) && isset($decoded['errors'][0]['message'])) {
                $message = $decoded['errors'][0]['message'];
            }
        }

        $this->logFailedResponse($statusCode, $body);

        if (401 === $statusCode || 403 === $statusCode) {
            throw new TokenException($message, $statusCode);
        }
        if (404 === $statusCode) {
            $message = 'Not found';
        }

        throw new ApiException($message, $statusCode);
    }

    /**
     * @param string $body
     * @param int $statusCode
     * @return mixed
     * @throws ApiException
     */
    private function decodeBody(string $body, int $statusCode): mixed {
        if ('' === $body) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new ApiException('Failed to decode JSON body-response: ' . json_last_error_msg(), $statusCode);
        }

        return $decoded;
    }

    /**
     * Reports a failed response to the PSR-3 logger when one is set, and falls
     * back to error_log() so failures are never silently dropped.
     *
     * @param int $statusCode
     * @param string $body
     * @return void
     */
    private function logFailedResponse(int $statusCode, string $body): void {
        if (null !== $this->logger) {
            $this->logger->warning('OnPay HTTP request failed', [
                'status' => $statusCode,
                'method' => $this->request->getMethod(),
                'uri' => $this->request->getUri(),
                'response' => $body,
            ]);
            return;
        }

        \error_log(sprintf(
            'REQUEST=%s %s, RESPONSE=%d %s',
            $this->request->getMethod(),
            $this->request->getUri(),
            $statusCode,
            $body
        ));
    }

    /**
     * @return TransactionService
     */
    public function transaction(): TransactionService {
        if (null === $this->transactionService) {
            $this->transactionService = new TransactionService($this);
        }
        return $this->transactionService;
    }

    /**
     * @return SubscriptionService
     */
    public function subscription(): SubscriptionService {
        if (null === $this->subscriptionService) {
            $this->subscriptionService = new SubscriptionService($this);
        }
        return $this->subscriptionService;
    }

    /**
     * @return PaymentService
     */
    public function payment(): PaymentService {
        if (null === $this->paymentService) {
            $this->paymentService = new PaymentService($this);
        }
        return $this->paymentService;
    }

    /**
     * @return GatewayService
     */
    public function gateway(): GatewayService {
        if (null === $this->gatewayService) {
            $this->gatewayService = new GatewayService($this);
        }
        return $this->gatewayService;
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array $headers
     * @param string $body
     * @return void
     */
    private function setLastHttpRequest(string $method, string $uri, array $headers, string $body): void {
        $httpRequest = new HttpRequest();
        $httpRequest->setMethod($method);
        $httpRequest->setUri($uri);
        $httpRequest->setHeaders($headers);
        $httpRequest->setBody($body);
        $this->request = $httpRequest;
    }

    /**
     * @param int $statusCode
     * @param string $body
     * @return void
     */
    private function setLastHttpResponse(int $statusCode, string $body): void {
        $httpResponse = new HttpResponse();
        $httpResponse->setStatusCode($statusCode);
        $httpResponse->setBody($body);
        $this->response = $httpResponse;
    }

    /**
     * Returns the last HTTP Request send to the API
     * @return HttpRequest
     */
    public function getLastHttpRequest(): ?HttpRequest {
        return $this->request;
    }

    /**
     * Returns the last HTTP Response received from the API
     * @return HttpResponse
     */
    public function getLastHttpResponse(): ?HttpResponse {
        return $this->response;
    }
}
