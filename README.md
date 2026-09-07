# OnPay.io PHP SDK (modernized fork)

[![Tests](https://github.com/tomsommer/onpay-php-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/tomsommer/onpay-php-sdk/actions/workflows/tests.yml)
[![Latest Stable Version](https://poser.pugx.org/tomsommer/onpay-php-sdk/v/stable)](https://packagist.org/packages/tomsommer/onpay-php-sdk)
[![License](https://poser.pugx.org/tomsommer/onpay-php-sdk/license)](https://packagist.org/packages/tomsommer/onpay-php-sdk)

A modernized PHP SDK for developing against the OnPay.io platform, forked from
[onpayio/php-sdk](https://github.com/onpayio/php-sdk).

API documentation at: https://manage.onpay.io/docs/api_v1.html

## What is different in this fork

The upstream SDK bundled a copy of `fkooman/oauth2-client` (a PHP 5.4-era library whose
own README recommends using something else) and hard-wired every HTTP call to cURL. This
fork replaces both:

- **OAuth 2.0 is handled by [`league/oauth2-client`](https://oauth2-client.thephpleague.com/).**
  The vendored `OnPay\OAuth\Client\*` tree is gone. Authorization, code exchange and token
  refresh go through a real, maintained library, and the provider is exposed via
  `getProvider()` so you can drive the flow yourself.
- **HTTP goes through any [PSR-18](https://www.php-fig.org/psr/psr-18/) client.** Pass your
  own client and [PSR-17](https://www.php-fig.org/psr/psr-17/) factories, or let them be
  auto-discovered. Symfony HttpClient, Guzzle and Buzz all work.
- **Failures are reported to a [PSR-3](https://www.php-fig.org/psr/psr-3/) logger**
  instead of `error_log()`. `OnPayAPI` implements `LoggerAwareInterface`.
- **PKCE is opt-in and actually works.** Upstream generated a code challenge and then sent
  an empty verifier. Set the `pkce_method` option and carry the verifier across the redirect
  with `getPkceCode()` / `setPkceCode()`.
- **Malformed JSON and transport errors raise typed exceptions** rather than yielding `null`.
- **`getLastHttpRequest()` / `getLastHttpResponse()` return the PSR-7 messages**, not a
  hand-rolled partial copy of them.
- **Payment-window verification is timing-safe and no longer over-collects fields.**
  `validatePayment()` compares the HMAC with `hash_equals()`, and it selects the signed
  fields by the `onpay_` *prefix* the window actually writes rather than by substring, so an
  unrelated parameter containing `onpay_` no longer breaks verification of a real payment.
  Calling it, or `getFormFields()`, without a window secret raises `MissingDataException`
  instead of hashing with an empty key.
- **`declare(strict_types=1)` in every file**, so amounts and identifiers are not silently
  coerced on the way through.
- **PHP 8.2+, native types on the core classes, and a CI suite** running PHPUnit on
  PHP 8.2/8.3/8.4 plus PHPStan.

### Upgrading from onpayio/php-sdk 1.x

`OnPay\OnPayAPI`, `OnPay\StaticToken`, `OnPay\TokenStorageInterface`, everything under
`OnPay\API\*` and the constructor signature `new OnPayAPI($tokenStorage, $options)` are
unchanged, so for most integrations only the Composer package name changes.

Breaking changes:

- The `OnPay\OAuth\Client\*`, `OnPay\InternalTokenStorage`, `OnPay\Session` and
  `OnPay\CurlHttpClientLogger` classes were removed. Nothing in the public API referenced them.
- `OnPay\API\Http\Request` and `OnPay\API\Http\Response` were removed too. They existed
  only to carry the last request and response, which are now the PSR-7 messages themselves:
  `getLastHttpRequest()` returns a `Psr\Http\Message\RequestInterface` and
  `getLastHttpResponse()` a `ResponseInterface`, with the body stream rewound. Callers get the
  real headers and status instead of a partial copy; `getUri()` now returns a `UriInterface`,
  so cast it to string.
- `TokenStorageInterface` now declares `getToken(): ?string` and `saveToken(string $token): void`.
  Add the types to your own implementation.
- `PaymentWindow::setSecret()` is typed `?string`, and `getSecret()` returns `?string`.
- `PaymentWindow::getFormFields()`, `generateSecret()` and `validatePayment()` throw
  `OnPay\API\Exception\MissingDataException` when no window secret has been set, rather
  than hashing with an empty key and returning a signature that can never match.
- The SDK no longer sets a cURL timeout of its own, because it no longer owns the transport.
  Configure the timeout on the HTTP client you inject.
- Stored tokens are written in `league/oauth2-client` format. Tokens written by 1.x are still
  read, so existing installations keep working without re-authorizing.
- A PSR-18 client and PSR-17 factories must be installable. `composer require` pulls in
  `php-http/discovery`, which finds any client you already have; install one (for example
  `symfony/http-client` with `nyholm/psr7`, or `guzzlehttp/guzzle`) if you have none.

## Requirements

PHP 8.2 and later, plus a PSR-18 HTTP client.

## Composer

You can install the SDK via [Composer](https://getcomposer.org/). Run the following command:
```bash
composer require tomsommer/onpay-php-sdk
```

The package `replace`s `onpayio/php-sdk`, so it can be dropped into a project that depends
on the upstream SDK without a conflict.

## Getting started



### Creating a payment window V3

A simple example of how to create a payment window v3.

Read more about the fields here: https://manage.onpay.io/docs/paymentwindow_v3.html

```php 
<?php 
require_once 'vendor/autoload.php';

$paymentWindow = new \OnPay\API\PaymentWindow();
$paymentWindow->setGatewayId("YourGatewayId");
$paymentWindow->setSecret("YourSecret");
$paymentWindow->setCurrency("DKK");
$paymentWindow->setAmount("123400");
// Reference must be unique (eg. invoice number)
$paymentWindow->setReference("UniqieReferenceId");
$paymentWindow->setAcceptUrl("https://example.com/payment?success=1");
$paymentWindow->setDeclineUrl("https://example.com/payment?success=0");
$paymentWindow->setWebsite('https://example.com/');
$paymentWindow->setType("payment");
$paymentWindow->setDesign("DesignName");
// Force 3D secure
$paymentWindow->setSecureEnabled(true);
// Set payment method to be card
$paymentWindow->setMethod(\OnPay\API\PaymentWindow::METHOD_CARD);
// Enable testmode
$paymentWindow->setTestMode(true);
$paymentWindow->setLanguage("en");

// Add additional info
$paymentInfo = new \OnPay\API\PaymentWindow\PaymentInfo();
$paymentInfo->setName('Test Pærsån');
$paymentInfo->setEmail('emil@example.com');
// And so on, a lot more fields should be set if data available for it

$paymentWindow->setInfo($paymentInfo);

?>

<?php if($paymentWindow->isValid()) { ?>
<form method="post" action="<?php echo $paymentWindow->getActionUrl(); ?>" accept-charset="UTF-8">
    <?php
        foreach ($paymentWindow->getFormFields() as $key => $value) { ?>
            <input type="hidden" name="<?php echo $key;?>" value="<?php echo htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');?>">
    <?php } ?>
    <input type="submit" value="Make payment">
</form>

<?php } else { ?>
    <h1>Payment window is not configured correct</h1>
<?php } ?>

```
You can create a transaction automatically when creating a new subscription using the following functions for the `$paymentWindow` variable:
```php
$paymentWindow->setType("subscription");
$paymentWindow->setSubscriptionWithTransaction(true);
```

Verifying a payment from the acceptment page can easily be done as following: 

```php
$payment = new \OnPay\API\PaymentWindow();
$payment->setSecret('YourSecret');

if($payment->validatePayment($_GET)) {
    echo "Payment was successfull";
} else {
    echo "There was an error with the payment";
}

```



### Using the API 

A simple usage example looks like:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

class TokenStorage implements \OnPay\TokenStorageInterface {
    protected $token;
    protected $fileName;

    public function __construct($filename) {
        $this->fileName = $filename;
        if (file_exists($filename)) {
            $this->token = file_get_contents($this->fileName);
        }
    }

    public function getToken() {
        return $this->token;
    }

    public function saveToken($token) {
        $this->token = $token;
        file_put_contents($this->fileName, $token);
    }
}

// TODO: It is extremely important that the .token.bin file is not accessible from the internet.
// It gives complete API access to anyone that gets a hold of it, treat it like a database password!
$tokenStorage = new TokenStorage(__DIR__ . '/.token.bin');

$onPayAPI = new \OnPay\OnPayAPI($tokenStorage, [
    'client_id' => 'example.com', // It is recommended to set it to the domain name the integration resides on
    'redirect_uri' => 'http://localhost/onpay-php-sdk/example2.php?auth',
    'gateway_id' => '1234', // Should be set to the gateway id you are integrating with
]);

// Special handling if we are about to auth against the API
if (isset($_GET['auth'])) {
    if (!isset($_GET['code'])) {
        $authUrl = $onPayAPI->authorize();
        header('Location: ' . $authUrl);
    } else {
        $onPayAPI->finishAuthorize($_GET['code']);
        echo 'Authorized :tada:' . PHP_EOL;
    }
    exit;
}

// Check if we need to authenticate
if (!$onPayAPI->isAuthorized()) {
    echo 'Not authorized for the API! ' . PHP_EOL;
    echo '<a href=?auth>Click here to initiate authorization</a>' . PHP_EOL;
    exit;
}

// Execute API method
var_dump($onPayAPI->ping());

```

### Choosing an HTTP client

By default the client and factories are discovered from what is installed:

```php
$onPayAPI = new \OnPay\OnPayAPI($tokenStorage, ['client_id' => 'example.com']);
```

To be explicit, or to reuse a configured client with your own timeouts, proxy and retries,
pass a PSR-18 client and PSR-17 factories:

```php
$psr18 = new \Symfony\Component\HttpClient\Psr18Client();

$onPayAPI = new \OnPay\OnPayAPI(
    $tokenStorage,
    ['client_id' => 'example.com'],
    $psr18,
    $psr18, // PSR-17 RequestFactoryInterface
    $psr18, // PSR-17 StreamFactoryInterface
);
```

All parameters are typed, so a DI container can autowire them. It can also be replaced later
with `setHttpClient()`.

### Logging

```php
$onPayAPI->setLogger($logger); // any PSR-3 LoggerInterface
```

Non-2xx responses are logged at warning level with the request method, URI, status and body.
Without a logger the SDK falls back to `error_log()`, so failures are never dropped silently.

### PKCE

PKCE requires the code verifier to survive the redirect, which the SDK cannot do for you:

```php
$onPayAPI = new \OnPay\OnPayAPI($tokenStorage, [
    'client_id' => 'example.com',
    'redirect_uri' => 'https://example.com/onpay/callback',
    'pkce_method' => \League\OAuth2\Client\Provider\AbstractProvider::PKCE_METHOD_S256,
]);

// Before redirecting, store the verifier next to the OAuth state.
$authUrl = $onPayAPI->authorize();
$_SESSION['onpay_pkce'] = $onPayAPI->getPkceCode();

// On the callback, restore it before exchanging the code.
$onPayAPI->setPkceCode($_SESSION['onpay_pkce']);
$onPayAPI->finishAuthorize($_GET['code']);
```

## Payments

```php
<?php
if($onPayAPI->isAuthorized()) {
    $paymentWindow = new OnPay\API\PaymentWindow();
    // -- Required fields -- //
    // see: https://onpay.io/docs/technical/api_v1.html#create-a-new-payment-request
    //   for a detailed explanation of each field.
    $paymentWindow->setCurrency("DKK");
    $paymentWindow->setAmount("39999");
    $paymentWindow->setReference('your_reference');
    $paymentWindow->setWebsite('https://yourwebsite');
    
    // -- Optional fields -- //
    // see: https://onpay.io/docs/technical/api_v1.html#create-a-new-payment-request
    //   for a detailed explanation of each field.
    $paymentWindow->setAcceptUrl('https://yourwebsite/accepturl.php');
    $paymentWindow->setDeclineUrl('https://yourwebsite/declineurl.php');
    $paymentWindow->setCallbackUrl('https://yourwebsite/callbackurl.php');
    $paymentWindow->setType('subscription');
    $paymentWindow->setMethod(OnPay\API\Util\PaymentMethods\Enums\Methods::CARD);
    $paymentWindow->setLanguage('da');
    $paymentWindow->setDesign('my_window');
    $paymentWindow->setExpiration(86400);
    $paymentWindow->setTestMode(true);
    
    // -- Optional Payment Info Fields -- //
    // see: https://onpay.io/docs/technical/api_v1.html#paymentinfo
    //   for a detailed explanation of each field.
    $paymentInfo = new OnPay\API\PaymentWindow\PaymentInfo();
    $paymentInfo->setName('individuals name');
    $paymentInfo->setEmail('individual@email.address');
    $paymentInfo->setAddressIdenticalShipping('Y'); // Or 'N'
    $paymentInfo->setDeliveryEmail('delivery@email.address');
    $paymentInfo->setDeliveryTimeFrame('03');
    $paymentInfo->setPreorder('Y');
    $paymentInfo->setPreorderDate(new DateTime('+1 month'));
    $paymentInfo->setReorder('N');
    $paymentInfo->setShippingMethod('01');
    
    // -- Optional Gift card options -- //
    $paymentInfo->setGiftCardAmount('3799');
    $paymentInfo->setGiftCardCount('1');
    
    // -- Optional Account options -- //
    // see: https://onpay.io/docs/technical/api_v1.html#account
    //   for a detailed explanation of each field.
    $paymentInfo->setAccountId('123-321');
    $paymentInfo->setAccountDateCreated('2020-02-15');
    $paymentInfo->setAccountDateChange('2020-03-02');
    $paymentInfo->setAccountDatePasswordChange('2020-03-02');
    $paymentInfo->setAccountPurchases(3);
    $paymentInfo->setAccountAttempts(0);
    $paymentInfo->setAccountShippingFirstUseDate('2020-02-15');
    $paymentInfo->setAccountShippingIdenticalName('Y');
    $paymentInfo->setAccountSuspicious('N');
    $paymentInfo->setAccountAttemptsDay('3');
    $paymentInfo->setAccountAttemptsYear('3');
    
    // -- Optional Shipping options -- //
    // see: https://onpay.io/docs/technical/api_v1.html#shipping
    //   for a detailed explanation of each field.
    $paymentInfo->setShippingAddressCity('city');
    $paymentInfo->setShippingAddressCountry('208');
    $paymentInfo->setShippingAddressLine1('line 1');
    $paymentInfo->setShippingAddressLine2('line 2');
    $paymentInfo->setShippingAddressLine3('line 3');
    $paymentInfo->setShippingAddressPostalCode('8660');
    $paymentInfo->setShippingAddressState('NY');
    
    // -- Optional Billing options -- //
    // see: https://onpay.io/docs/technical/api_v1.html#billing
    //   for a detailed explanation of each field.
    $paymentInfo->setBillingAddressCity('city');
    $paymentInfo->setBillingAddressCountry('208');
    $paymentInfo->setBillingAddressLine1('line 1');
    $paymentInfo->setBillingAddressLine2('line 2');
    $paymentInfo->setBillingAddressLine3('line 3');
    $paymentInfo->setBillingAddressPostalCode('8660');
    $paymentInfo->setBillingAddressState('NY');
    
    // -- Optional Phone options -- //
    // see: https://onpay.io/docs/technical/api_v1.html#phone
    //   for a detailed explanation of each field.
    $paymentInfo->setPhoneHome('45', '37123456');
    $paymentInfo->setPhoneMobile('45', '37123456');
    $paymentInfo->setPhoneWork('45', '37123456');
    
    // Assign the above settings to the window
    $paymentWindow->setInfo($paymentInfo);
    
    // -- (N.B Required for some card methods!) Cart options -- //
    // see: https://onpay.io/docs/technical/api_v1.html#paymentcart
    //   for a detailed explanation of each field.
    $paymentCart = new OnPay\API\PaymentWindow\Cart();
    // see: https://onpay.io/docs/technical/api_v1.html#shippingobject
    $paymentCart->setShipping(500, 25, 40);
    // see: https://onpay.io/docs/technical/api_v1.html#handlingobject 
    $paymentCart->setHandling(500, 25);
    $paymentCart->setDiscount(25);
    // see: https://onpay.io/docs/technical/api_v1.html#itemobject
    $cartItem1 = new OnPay\API\PaymentWindow\CartItem('item1 name', 250, 1, 12, 'item1 description', 'item1 sku');
    $cartItem2 = new OnPay\API\PaymentWindow\CartItem('item2 name', 250, 1, 12, 'item2 description', 'item2 sku');
    $paymentCart->setItems([$cartItem1, $cartItem2]);
    
    // Assign the cart to the window
    $paymentWindow->setCart($paymentCart);
    
    // Finally - submit the payment request via the api
    $paymentService = new OnPay\API\PaymentService($onPayAPI);
    $paymentResult = $paymentService->createNewPayment($paymentWindow);
}

```


## Transactions 

```php 
<?php 
if($onPayAPI->isAuthorized()) {

    // Get list of transactions
    $onPayAPI->transaction()->getTransactions()->transactions;
    // Get the pagination object
    $onPayAPI->transaction()->getTransactions()->pagination;
    
    // Get specific transaction
    $onPayAPI->transaction()->getTransaction("00000000-0000-0000-0000-000000000000");

    // Capture transaction
    $onPayAPI->transaction()->captureTransaction("00000000-0000-0000-0000-000000000000");

    // Cancel transaction 
    $onPayAPI->transaction()->cancelTransaction("00000000-0000-0000-0000-000000000000");

}

```

## Subscriptions

```php
<?php
if($onPayAPI->isAuthorized()) {
    // Get list of subscriptions
    $onPayAPI->subscription()->getSubscriptions()->subscriptions;
    // Get the pagination object
     $onPayAPI->subscription()->getSubscriptions()->pagination;
   
   
    // Get details about a specific subscription
    $onPayAPI->subscription()->getSubscription("00000000-0000-0000-0000-000000000000");

    // Create transaction from subscription
    $onPayAPI->subscription()->createTransactionFromSubscription("00000000-0000-0000-0000-000000000000", 100, "orderId");
}

```
