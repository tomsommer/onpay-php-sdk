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
  refresh go through a real, maintained library via
  [`tomsommer/oauth2-onpay`](https://github.com/tomsommer/oauth2-onpay), a standalone League
  provider client. It is exposed via `getProvider()` so you can drive the flow yourself, or
  use it on its own if you only need authorization and not the API client.
- **HTTP goes through any [PSR-18](https://www.php-fig.org/psr/psr-18/) client.** Pass your
  own client and [PSR-17](https://www.php-fig.org/psr/psr-17/) factories, or let them be
  auto-discovered. Symfony HttpClient, Guzzle and Buzz all work.
- **Failures are reported to a [PSR-3](https://www.php-fig.org/psr/psr-3/) logger**
  instead of `error_log()`. `OnPayAPI` implements `LoggerAwareInterface`.
- **The OAuth callback can verify `state`.** Hand `finishAuthorize()` the state from
  the callback and the one you stored before redirecting and they are compared with
  `hash_equals()` before the code is spent. Upstream compared against `crypt()` of a
  fixed value, which is not a check at all.
- **PKCE is opt-in and actually works.** Upstream generated a code challenge and then sent
  an empty verifier. Set the `pkce_method` option and carry the verifier across the redirect
  with `getPkceCode()` / `setPkceCode()`.
- **Malformed JSON and transport errors raise typed exceptions** rather than yielding `null`.
- **Payment-window verification is timing-safe and no longer over-collects fields.**
  `validatePayment()` compares the HMAC with `hash_equals()`, and it selects the signed
  fields by the `onpay_` *prefix* the window actually writes rather than by substring, so an
  unrelated parameter containing `onpay_` no longer breaks verification of a real payment.
  Calling it, or `getFormFields()`, without a window secret raises `MissingDataException`
  instead of hashing with an empty key.
- **`declare(strict_types=1)` in every file**, so amounts and identifiers are not silently
  coerced on the way through.
- **The core is three collaborators rather than one class.** `OnPayAPI` is a facade over
  `OnPay\Auth\TokenManager` (reads, refreshes and stores the token) and
  `OnPay\Http\ApiClient` (sends authenticated requests, maps answers to typed exceptions).
  The service classes depend on `OnPay\Http\ApiClientInterface`, a two-verb seam that is
  trivial to substitute in a test.
- **PHP 8.2+, native types on the core classes, and a CI suite** running PHPUnit on
  PHP 8.2/8.3/8.4 plus PHPStan.

### Upgrading from onpayio/php-sdk 1.x

`OnPay\OnPayAPI`, `OnPay\StaticToken`, everything under `OnPay\API\*` and the constructor
signature `new OnPayAPI($tokenStorage, $options)` are unchanged, so for most integrations only
the Composer package name changes.

Tokens written by 1.x are still read, so existing installations keep working without
re-authorizing.

What changed in each version, and what to do about it, is in the
[release notes](https://github.com/tomsommer/onpay-php-sdk/releases).

## Requirements

PHP 8.2 and later, plus a PSR-18 HTTP client. `composer require` pulls in
[`php-http/discovery`](https://docs.php-http.org/en/latest/discovery.html), which finds any
client already installed; if you have none, add one (for example `symfony/http-client` with
`nyholm/psr7`, or `guzzlehttp/guzzle`).

The SDK sets no timeout of its own, because it does not own the transport. Configure that on
the client you inject.

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
        // Store the state so the callback can be tied back to this request.
        $_SESSION['onpay_oauth_state'] = $onPayAPI->getState();
        header('Location: ' . $authUrl);
    } else {
        $onPayAPI->finishAuthorize(
            $_GET['code'],
            $_GET['state'] ?? null,
            $_SESSION['onpay_oauth_state'] ?? null
        );
        unset($_SESSION['onpay_oauth_state']);
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

All parameters are typed, so a DI container can autowire them.

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
$_SESSION['onpay_oauth_state'] = $onPayAPI->getState();
$_SESSION['onpay_pkce'] = $onPayAPI->getPkceCode();

// On the callback, restore it before exchanging the code.
$onPayAPI->setPkceCode($_SESSION['onpay_pkce']);
$onPayAPI->finishAuthorize($_GET['code'], $_GET['state'] ?? null, $_SESSION['onpay_oauth_state'] ?? null);
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
    $paymentResult = $onPayAPI->payment()->createNewPayment($paymentWindow);
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

## Transaction events

Every event across the gateway's transactions, oldest first. Paged by cursor
rather than page number: keep the cursor and pass it back to carry on.

```php
$cursor = $yourStorage->get('onpay_event_cursor');

do {
    $events = $onPayAPI->transaction()->getEvents($cursor);

    foreach ($events->events as $event) {
        // $event->action is one of TransactionEvent::ACTION_*
        // $event->transaction, ->amount, ->successful, ->dateTime, ->resultText
    }

    if ($events->hasMore()) {
        $cursor = $events->nextCursor;
        $yourStorage->set('onpay_event_cursor', $cursor);
    }
} while ($events->hasMore());
```

An empty cursor means nothing further right now; the one you already hold stays
valid for asking again later.

## Acquirers, providers and wallets

```php
foreach ($onPayAPI->acquirer()->getAcquirers() as $acquirer) {
    echo $acquirer->name, $acquirer->active ? " (active)\n" : "\n";
}

$nets = $onPayAPI->acquirer()->getAcquirer('nets');
$nets->getSetting('mcc');
$nets->hasLowValueScaExemption();

// Which fields are writable depends on the acquirer.
$onPayAPI->acquirer()->updateAcquirer('nets', ['active' => true, 'mcc' => '5734']);

$onPayAPI->acquirer()->getProviders();   // Klarna, ViaBill, ...
$onPayAPI->acquirer()->getWallets();     // applepay, mobilepay, ...
```

Only `name` and `active` are common to every acquirer. The rest differs — Nets
carries card BINs, Clearhaus an API key — so it is kept as returned and reached
through `getSetting()` rather than flattened into properties that would be null
for most acquirers.

## Payment window languages

```php
foreach ($onPayAPI->gateway()->getPaymentWindowLanguages() as $language) {
    echo $language->locale, "\n";   // da, de, en, ...
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
