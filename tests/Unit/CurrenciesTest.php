<?php

declare(strict_types=1);

namespace Tests\Unit;

use OnPay\API\Exception\ApiException;
use OnPay\API\Util\Currencies;
use OnPay\API\Util\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurrenciesTest extends TestCase
{
    /**
     * The currencies OnPay documents as accepted, in the ISO 4217 numeric order
     * their reference uses.
     *
     * @see https://onpay.io/docs/technical/index.html
     */
    private const DOCUMENTED = [
        'AUD', 'CAD', 'CNY', 'CZK', 'DKK', 'HUF', 'ISK', 'INR', 'JPY', 'NZD',
        'NOK', 'RUB', 'SGD', 'ZAR', 'SZL', 'SEK', 'CHF', 'EGP', 'GBP', 'USD',
        'RON', 'TRY', 'EUR', 'UAH', 'PLN', 'BRL',
    ];

    /**
     * The allow-list is what OnPay accepts, not all of ISO 4217, so it drifts
     * whenever OnPay adds a currency. This is the test that notices.
     */
    public function testAllowListMatchesWhatOnPayDocuments(): void
    {
        $ours = array_keys(Currencies::CURRENCIES);
        sort($ours);
        $documented = self::DOCUMENTED;
        sort($documented);

        $this->assertSame($documented, $ours);
    }

    #[DataProvider('documentedCurrencyProvider')]
    public function testEveryDocumentedCurrencyIsUsable(string $code): void
    {
        $currency = new Currency($code);

        $this->assertSame($code, $currency->getAlpha3());
        $this->assertGreaterThan(0, $currency->getISO4217());
        $this->assertContains($currency->getExponent(), [0, 2, 3]);
    }

    /** @return array<string, string[]> */
    public static function documentedCurrencyProvider(): array
    {
        return array_combine(
            self::DOCUMENTED,
            array_map(static fn (string $c): array => [$c], self::DOCUMENTED)
        );
    }

    #[DataProvider('recentlyAddedProvider')]
    public function testRecentlyAddedCurrenciesResolveBothWays(string $code, int $numeric): void
    {
        $this->assertSame($numeric, (new Currency($code))->getISO4217());
        $this->assertSame($code, (new Currency($numeric))->getAlpha3());
        $this->assertSame(2, (new Currency($code))->getExponent());
    }

    /** @return array<string, array{string, int}> */
    public static function recentlyAddedProvider(): array
    {
        return [
            'HUF' => ['HUF', 348],
            'RON' => ['RON', 946],
            'TRY' => ['TRY', 949],
        ];
    }

    public function testUndocumentedCurrencyIsStillRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unsupported currency provided: ZWL');
        new Currency('ZWL');
    }
}
