<?php

declare(strict_types=1);

namespace Tests\Unit;

use OnPay\API\Exception\MissingDataException;
use OnPay\API\PaymentWindow;
use PHPUnit\Framework\TestCase;

class PaymentWindowTest extends TestCase {
    private const SECRET = 'window_secret';

    private function window(): PaymentWindow {
        $window = new PaymentWindow();
        $window->setSecret(self::SECRET);
        $window->setCurrency('DKK');
        $window->setAmount('39999');
        $window->setReference('order-1');
        $window->setWebsite('https://example.test/');

        return $window;
    }

    /**
     * OnPay documents onpay_website as required, so a window without it would be
     * turned away at the redirect. isValid() has to say so first.
     *
     * @see https://onpay.io/docs/technical/paymentwindow_v3.html
     */
    public function testWindowWithoutWebsiteIsInvalid(): void {
        $window = new PaymentWindow();
        $window->setSecret(self::SECRET);
        $window->setGatewayId('A5KM3QX7B');
        $window->setCurrency('DKK');
        $window->setAmount('39999');
        $window->setReference('order-1');
        $window->setAcceptUrl('https://example.test/ok');

        $this->assertFalse($window->isValid());

        $window->setWebsite('https://example.test/');
        $this->assertTrue($window->isValid());
    }

    public function testFormFieldsValidateAgainstThemselves(): void {
        $fields = $this->window()->getFormFields();

        $this->assertArrayHasKey('onpay_hmac_sha1', $fields);
        $this->assertTrue($this->window()->validatePayment($fields));
    }

    public function testTamperedFieldFailsValidation(): void {
        $fields = $this->window()->getFormFields();
        $fields['onpay_amount'] = '1';

        $this->assertFalse($this->window()->validatePayment($fields));
    }

    public function testMissingHmacFailsValidation(): void {
        $fields = $this->window()->getFormFields();
        unset($fields['onpay_hmac_sha1']);

        $this->assertFalse($this->window()->validatePayment($fields));
    }

    /**
     * hash_equals() rejects a non-string, so an array-valued hmac parameter
     * (?onpay_hmac_sha1[]=x) must be turned away before it reaches the compare.
     */
    public function testArrayHmacFailsValidationWithoutError(): void {
        $fields = $this->window()->getFormFields();
        $fields['onpay_hmac_sha1'] = ['not', 'a', 'string'];

        $this->assertFalse($this->window()->validatePayment($fields));
    }

    /**
     * Fields are signed with an 'onpay_' prefix, so only prefixed parameters may
     * be fed back into the comparison. A parameter merely containing 'onpay_'
     * used to be collected too, which broke validation of legitimate payments.
     */
    public function testUnrelatedParameterContainingThePrefixIsIgnored(): void {
        $fields = $this->window()->getFormFields();
        $fields['tracking_onpay_campaign'] = 'summer';
        $fields['utm_source'] = 'newsletter';

        $this->assertTrue($this->window()->validatePayment($fields));
    }

    /**
     * Every onpay_-prefixed parameter present takes part in the comparison, so
     * an injected one cannot slip past: it changes the signed query string.
     */
    public function testInjectedOnpayParameterIsRejected(): void {
        $fields = $this->window()->getFormFields();
        $fields['onpay_evil'] = '1';

        $this->assertFalse($this->window()->validatePayment($fields));
    }

    public function testRemovingASignedParameterIsRejected(): void {
        $fields = $this->window()->getFormFields();
        unset($fields['onpay_reference']);

        $this->assertFalse($this->window()->validatePayment($fields));
    }

    /**
     * Nothing the window signs is emitted without the onpay_ prefix, which is
     * what makes prefix selection on the way back in lossless.
     */
    public function testEverySignedFieldCarriesThePrefix(): void {
        $keys = array_keys($this->window()->getFormFields());
        $unprefixed = array_filter($keys, static fn ($key): bool => !str_starts_with((string) $key, 'onpay_'));

        $this->assertSame([], array_values($unprefixed));
    }

    public function testNumericParameterKeyIsIgnored(): void {
        $fields = $this->window()->getFormFields();
        $fields[0] = 'stray';

        $this->assertTrue($this->window()->validatePayment($fields));
    }

    public function testMissingSecretIsReportedRatherThanHashedWithAnEmptyKey(): void {
        $window = new PaymentWindow();
        $window->setCurrency('DKK');
        $window->setAmount('39999');
        $window->setReference('order-1');
        $window->setWebsite('https://example.test/');

        $this->expectException(MissingDataException::class);
        $this->expectExceptionMessage('No window secret set');
        $window->getFormFields();
    }

    public function testValidatePaymentWithoutSecretIsReported(): void {
        $fields = $this->window()->getFormFields();

        $this->expectException(MissingDataException::class);
        (new PaymentWindow())->validatePayment($fields);
    }

    public function testHmacIsSensitiveToTheSecret(): void {
        $fields = $this->window()->getFormFields();

        $other = $this->window();
        $other->setSecret('a_different_secret');

        $this->assertFalse($other->validatePayment($fields));
    }
}
