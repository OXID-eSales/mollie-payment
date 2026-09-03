<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Payments\Mollie\Admin\PaymentMethodLabels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 136 — raw Mollie method code → admin lang key. One case per supported
 * method, so a newly mapped method cannot silently fall through and a removed
 * one cannot go unnoticed.
 */
#[CoversClass(PaymentMethodLabels::class)]
#[Group('sprint-136')]
final class PaymentMethodLabelsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function supportedMethods(): array
    {
        return [
            'creditcard'     => ['creditcard', 'MOLLIE_PAYMENT_METHOD_CREDITCARD'],
            'applepay'       => ['applepay', 'MOLLIE_PAYMENT_METHOD_APPLE_PAY'],
            'googlepay'      => ['googlepay', 'MOLLIE_PAYMENT_METHOD_GOOGLE_PAY'],
            'paypal'         => ['paypal', 'MOLLIE_PAYMENT_METHOD_PAYPAL'],
            'klarna'         => ['klarna', 'MOLLIE_PAYMENT_METHOD_KLARNA'],
            'klarnapaylater' => ['klarnapaylater', 'MOLLIE_PAYMENT_METHOD_KLARNA'],
            'klarnasliceit'  => ['klarnasliceit', 'MOLLIE_PAYMENT_METHOD_KLARNA'],
            'klarnapaynow'   => ['klarnapaynow', 'MOLLIE_PAYMENT_METHOD_KLARNA'],
            'directdebit'    => ['directdebit', 'MOLLIE_PAYMENT_METHOD_DIRECTDEBIT'],
            'banktransfer'   => ['banktransfer', 'MOLLIE_PAYMENT_METHOD_BANKTRANSFER'],
            'ideal'          => ['ideal', 'MOLLIE_PAYMENT_METHOD_IDEAL'],
            'bancontact'     => ['bancontact', 'MOLLIE_PAYMENT_METHOD_BANCONTACT'],
            'sofort'         => ['sofort', 'MOLLIE_PAYMENT_METHOD_SOFORT'],
            'eps'            => ['eps', 'MOLLIE_PAYMENT_METHOD_EPS'],
            'przelewy24'     => ['przelewy24', 'MOLLIE_PAYMENT_METHOD_PRZELEWY24'],
            'giftcard'       => ['giftcard', 'MOLLIE_PAYMENT_METHOD_GIFTCARD'],
            'voucher'        => ['voucher', 'MOLLIE_PAYMENT_METHOD_VOUCHER'],
            'belfius'        => ['belfius', 'MOLLIE_PAYMENT_METHOD_BELFIUS'],
            'kbc'            => ['kbc', 'MOLLIE_PAYMENT_METHOD_KBC'],
            'twint'          => ['twint', 'MOLLIE_PAYMENT_METHOD_TWINT'],
            'trustly'        => ['trustly', 'MOLLIE_PAYMENT_METHOD_TRUSTLY'],
            'in3'            => ['in3', 'MOLLIE_PAYMENT_METHOD_IN3'],
            'riverty'        => ['riverty', 'MOLLIE_PAYMENT_METHOD_RIVERTY'],
            'billie'         => ['billie', 'MOLLIE_PAYMENT_METHOD_BILLIE'],
            'alma'           => ['alma', 'MOLLIE_PAYMENT_METHOD_ALMA'],
            'blik'           => ['blik', 'MOLLIE_PAYMENT_METHOD_BLIK'],
            'mbway'          => ['mbway', 'MOLLIE_PAYMENT_METHOD_MBWAY'],
            'multibanco'     => ['multibanco', 'MOLLIE_PAYMENT_METHOD_MULTIBANCO'],
            'satispay'       => ['satispay', 'MOLLIE_PAYMENT_METHOD_SATISPAY'],
            'paybybank'      => ['paybybank', 'MOLLIE_PAYMENT_METHOD_PAYBYBANK'],
            'payconiq'       => ['payconiq', 'MOLLIE_PAYMENT_METHOD_PAYCONIQ'],
            'pointofsale'    => ['pointofsale', 'MOLLIE_PAYMENT_METHOD_POINTOFSALE'],
        ];
    }

    #[DataProvider('supportedMethods')]
    public function testSupportedMethodsMapToTheirLangKey(string $raw, string $expected): void
    {
        self::assertSame($expected, PaymentMethodLabels::keyFor($raw));
    }

    public function testUnmappedMethodHasNoKeySoTheRawCodeCanBeShown(): void
    {
        self::assertNull(PaymentMethodLabels::keyFor('some_new_mollie_method'));
    }

    public function testEmptyCodeHasNoKey(): void
    {
        self::assertNull(PaymentMethodLabels::keyFor(''));
    }

    public function testEveryMappedKeyExistsInBothAdminLangFiles(): void
    {
        $en = $this->langFile(__DIR__ . '/../../../views/admin_twig/en/mollie_lang.php');
        $de = $this->langFile(__DIR__ . '/../../../views/admin_twig/de/mollie_lang.php');

        foreach (self::supportedMethods() as [$raw, $key]) {
            self::assertArrayHasKey($key, $en, "missing EN translation for {$raw}");
            self::assertArrayHasKey($key, $de, "missing DE translation for {$raw}");
        }

        self::assertArrayHasKey('MOLLIE_PAYMENT_METHOD_USED', $en);
        self::assertArrayHasKey('MOLLIE_PAYMENT_METHOD_USED', $de);
    }

    /**
     * @return array<string, string>
     */
    private function langFile(string $path): array
    {
        self::assertFileExists($path);

        $aLang = [];
        require $path;

        /** @var array<string, string> $aLang */
        return $aLang;
    }
}
