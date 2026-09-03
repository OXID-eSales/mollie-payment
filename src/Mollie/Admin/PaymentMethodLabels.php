<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

/**
 * Raw Mollie payment-method code → admin language key.
 *
 * Sprint 136: the admin "Payment" tab shows the method the customer actually
 * paid with. Mollie reports it as a lowercase code in `payment.method`, or as a
 * wallet marker in `payment.details.wallet`; this map turns that code into a
 * translatable ident.
 *
 * Stateless, deterministic, dependency-free — hence `final` + static rather than
 * an injected service. It lives under `Admin/` and not `Service/`, which
 * `services.yaml` sweeps as a DI resource.
 *
 * Klarna's four product codes collapse onto one label: "Klarna Pay later" vs
 * "Klarna Slice it" is a checkout distinction, and the panel's question is who
 * holds the money.
 *
 * An unmapped code deliberately returns null instead of an "unknown" key —
 * showing the operator `some_new_method` beats showing them "Unknown".
 */
final class PaymentMethodLabels
{
    /**
     * @var array<string, string>
     */
    private const LABEL_KEYS = [
        // Cards and wallets
        'creditcard'     => 'MOLLIE_PAYMENT_METHOD_CREDITCARD',
        'applepay'       => 'MOLLIE_PAYMENT_METHOD_APPLE_PAY',
        'googlepay'      => 'MOLLIE_PAYMENT_METHOD_GOOGLE_PAY',
        'paypal'         => 'MOLLIE_PAYMENT_METHOD_PAYPAL',
        // Buy now, pay later
        'klarna'         => 'MOLLIE_PAYMENT_METHOD_KLARNA',
        'klarnapaylater' => 'MOLLIE_PAYMENT_METHOD_KLARNA',
        'klarnasliceit'  => 'MOLLIE_PAYMENT_METHOD_KLARNA',
        'klarnapaynow'   => 'MOLLIE_PAYMENT_METHOD_KLARNA',
        'in3'            => 'MOLLIE_PAYMENT_METHOD_IN3',
        'riverty'        => 'MOLLIE_PAYMENT_METHOD_RIVERTY',
        'billie'         => 'MOLLIE_PAYMENT_METHOD_BILLIE',
        'alma'           => 'MOLLIE_PAYMENT_METHOD_ALMA',
        // Bank debits and transfers
        'directdebit'    => 'MOLLIE_PAYMENT_METHOD_DIRECTDEBIT',
        'banktransfer'   => 'MOLLIE_PAYMENT_METHOD_BANKTRANSFER',
        'paybybank'      => 'MOLLIE_PAYMENT_METHOD_PAYBYBANK',
        'trustly'        => 'MOLLIE_PAYMENT_METHOD_TRUSTLY',
        // Bank redirects
        'ideal'          => 'MOLLIE_PAYMENT_METHOD_IDEAL',
        'bancontact'     => 'MOLLIE_PAYMENT_METHOD_BANCONTACT',
        'sofort'         => 'MOLLIE_PAYMENT_METHOD_SOFORT',
        'eps'            => 'MOLLIE_PAYMENT_METHOD_EPS',
        'przelewy24'     => 'MOLLIE_PAYMENT_METHOD_PRZELEWY24',
        'belfius'        => 'MOLLIE_PAYMENT_METHOD_BELFIUS',
        'kbc'            => 'MOLLIE_PAYMENT_METHOD_KBC',
        'blik'           => 'MOLLIE_PAYMENT_METHOD_BLIK',
        'mbway'          => 'MOLLIE_PAYMENT_METHOD_MBWAY',
        'multibanco'     => 'MOLLIE_PAYMENT_METHOD_MULTIBANCO',
        'payconiq'       => 'MOLLIE_PAYMENT_METHOD_PAYCONIQ',
        'satispay'       => 'MOLLIE_PAYMENT_METHOD_SATISPAY',
        'twint'          => 'MOLLIE_PAYMENT_METHOD_TWINT',
        // Vouchers and in-person
        'giftcard'       => 'MOLLIE_PAYMENT_METHOD_GIFTCARD',
        'voucher'        => 'MOLLIE_PAYMENT_METHOD_VOUCHER',
        'pointofsale'    => 'MOLLIE_PAYMENT_METHOD_POINTOFSALE',
    ];

    /**
     * Language key for a raw Mollie method or wallet code, or null when the code
     * is unmapped (caller shows the raw code) or empty.
     */
    public static function keyFor(string $rawType): ?string
    {
        return self::LABEL_KEYS[$rawType] ?? null;
    }
}
