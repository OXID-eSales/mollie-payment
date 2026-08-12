<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Core;

/**
 * Centralised constants and payment-method definitions for the Mollie module.
 *
 * All string identifiers that leak into the rest of the module (payment IDs, event names,
 * capture modes, mode flags, provider name) live here so nothing else needs to hardcode them.
 * Mirrors PayPal's PayPalDefinitions; lesson from Stripe sprint 56b: hardcoded payment IDs
 * cause ORDER_STATE_INVALIDPAYMENT.
 */
final class MollieDefinitions
{
    // Module + payment identity
    public const MODULE_ID = 'oe_payments_mollie';
    public const PAYMENT_ID = 'oe_payments_mollie';

    // The provider name stored on the contract via setProvider() and matched by the
    // panel provider / event translator / webhook processor.
    public const PROVIDER_NAME = 'mollie';

    // The `cl=` value of the controller that handles the post-checkout return
    // (index.php?cl=<this>&fnc=checkoutReturn). MollieOrderController is a class-chain
    // extension of OXID's core OrderController (metadata.php `extend`), so it is reachable
    // at the core's own `cl=order`, not a standalone controller key.
    public const ORDER_CONTROLLER_ID = 'order';

    // The `cl=` value of the webhook endpoint controller. Registered in metadata.php `controllers`.
    public const WEBHOOK_CONTROLLER_ID = 'MollieWebhookController';

    // Transaction types recorded via TransactionRepositoryInterface (Sprint 5 webhook pipeline).
    public const TRANSACTION_TYPE_CAPTURE = 'capture';
    public const TRANSACTION_TYPE_FAILURE = 'failure';
    public const TRANSACTION_TYPE_EXPIRATION = 'expiration';
    public const TRANSACTION_TYPE_CANCELLATION = 'cancellation';
    public const TRANSACTION_TYPE_CHARGEBACK = 'chargeback';
    public const TRANSACTION_TYPE_AUTHORIZATION = 'authorization';

    // Transaction statuses recorded via TransactionRepositoryInterface.
    public const TRANSACTION_STATUS_COMPLETED = 'completed';
    public const TRANSACTION_STATUS_FAILED = 'failed';

    // Mode flags (metadata setting values). Mollie API keys are prefixed test_ / live_.
    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';

    // Capture modes (metadata setting values).
    public const CAPTURE_MODE_AUTOMATIC = 'automatic';
    public const CAPTURE_MODE_MANUAL = 'manual';

    // Logging levels (metadata setting values). Mirrors Stripe's harmonised logging design.
    public const LOG_LEVEL_OFF = 'off';
    public const LOG_LEVEL_ERRORS = 'errors';
    public const LOG_LEVEL_NORMAL = 'normal';
    public const LOG_LEVEL_DEBUG = 'debug';

    // Provider-internal (module-event) names.
    public const EVENT_CHECKOUT_SESSION_REQUEST = 'oe_payments.mollie.checkout_session_request';
    public const EVENT_CHECKOUT_RETURN = 'oe_payments.mollie.checkout_return';
    public const EVENT_CAPTURE_REQUEST = 'oe_payments.mollie.capture_request';
    public const EVENT_REFUND_REQUEST = 'oe_payments.mollie.refund_request';
    public const EVENT_CANCEL_AUTHORIZATION = 'oe_payments.mollie.cancel_authorization';

    /**
     * Mollie method ids that support MANUAL capture (the authorize-then-capture / two-step flow).
     * When the shop runs in manual-capture mode, only these may be offered inline — otherwise Mollie
     * rejects the create-payment with 422 "At least one of the provided payment methods must support
     * captures". This is the reliably-capture-capable set (cards + Buy-Now-Pay-Later); instant methods
     * (iDEAL, Bancontact, bank transfer, …) never support captures and are filtered out.
     *
     * PayPal is intentionally EXCLUDED: although Mollie documents PayPal as capture-capable, manual
     * capture requires extra PayPal onboarding and is rejected on accounts without it (verified on the
     * test profile), so it is not offered under manual capture by default.
     */
    public const MANUAL_CAPTURE_METHODS = [
        'creditcard',
        'klarna',
        'klarnapaylater',
        'klarnasliceit',
        'klarnapaynow',
        'riverty',
        'billie',
        'in3',
    ];

    /**
     * Methods still not offerable inline. Voucher/meal-voucher need per-line `orderLineCategories`
     * that the order-data provider does not yet emit (deferred — MOLLIE-ORDERS-API scope D2). The
     * pay-later methods below WERE here too but are now supported: the create-payment sends a billing
     * address + reconciled lines (see {@see self::METHODS_REQUIRING_ORDER_DATA}).
     */
    public const INLINE_UNSUPPORTED_METHODS = [
        'voucher',
        'mealvoucher',
    ];

    /**
     * Pay-later methods that require a billing address + order lines on the create-payment (Mollie
     * 422s "A billing address is required" otherwise). For these the CheckoutPaymentService pulls the
     * order data via {@see \OxidEsales\Payments\Mollie\Service\MollieOrderDataProviderInterface}.
     */
    public const METHODS_REQUIRING_ORDER_DATA = [
        'klarna',
        'klarnapaylater',
        'klarnasliceit',
        'klarnapaynow',
        'riverty',
        'billie',
        'in3',
    ];

    // Payment-method constraints (narrow default; EUR-first like Mollie's core markets).
    private const PAYMENT_CONSTRAINTS_DEFAULT = [
        'oxfromamount' => 0.01,
        'oxtoamount' => 999999,
        'oxaddsumtype' => 'abs',
    ];

    private const MOLLIE_DEFINITIONS = [
        self::PAYMENT_ID => [
            'descriptions' => [
                'de' => [
                    'desc' => 'Mollie',
                    'longdesc' => 'Bezahlen Sie sicher mit Mollie (iDEAL, Kreditkarte, u. a.).',
                ],
                'en' => [
                    'desc' => 'Mollie',
                    'longdesc' => 'Pay securely with Mollie (iDEAL, credit card and more).',
                ],
            ],
            'countries' => [],
            'currencies' => ['EUR'],
            'constraints' => self::PAYMENT_CONSTRAINTS_DEFAULT,
            'defaulton' => true,
            'paymenttype' => 'mollie',
        ],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getMollieDefinitions(): array
    {
        return self::MOLLIE_DEFINITIONS;
    }

    public static function isMolliePayment(string $paymentId): bool
    {
        return isset(self::MOLLIE_DEFINITIONS[$paymentId]);
    }

    /**
     * @return list<string>
     */
    public static function getSupportedCurrencies(string $paymentId): array
    {
        $currencies = self::MOLLIE_DEFINITIONS[$paymentId]['currencies'] ?? [];
        return array_values(array_filter($currencies, 'is_string'));
    }

    /**
     * @return list<string>
     */
    public static function getSupportedCountries(string $paymentId): array
    {
        $countries = self::MOLLIE_DEFINITIONS[$paymentId]['countries'] ?? [];
        return array_values(array_filter($countries, 'is_string'));
    }

    /**
     * Sprint 11 Story 11 (F16): an empty currency list means "unknown", and unknown must not mean
     * "everything is allowed".
     *
     * The old form (`$currencies === [] || in_array(...)`) returned true for an unknown payment id or
     * a typo'd/removed `currencies` key — a gate whose failure mode was to allow every currency. It
     * was moot only because the definition happens to list EUR; combined with the `'EUR'` fallbacks
     * removed in the same story, a shop with an unreadable currency could pass this check with a
     * guess and then fail at create-payment.
     */
    public static function supportsCurrency(string $paymentId, string $currency): bool
    {
        $currencies = self::getSupportedCurrencies($paymentId);
        if ($currencies === []) {
            return false;
        }

        return in_array(strtoupper($currency), $currencies, true);
    }

    /**
     * True when the Mollie method id supports manual (two-step) capture — see
     * {@see self::MANUAL_CAPTURE_METHODS}. Used to hide capture-incompatible methods from the inline
     * selector on manual-capture shops.
     */
    public static function supportsManualCapture(string $methodId): bool
    {
        return in_array(strtolower($methodId), self::MANUAL_CAPTURE_METHODS, true);
    }

    /**
     * True when the Mollie method can be offered on the inline selector with the data the module's
     * create-payment flow sends (i.e. it is NOT a Buy-Now-Pay-Later/voucher method that needs an
     * upfront billing address + order lines). See {@see self::INLINE_UNSUPPORTED_METHODS}.
     */
    public static function isOfferableInline(string $methodId): bool
    {
        return !in_array(strtolower($methodId), self::INLINE_UNSUPPORTED_METHODS, true);
    }

    /**
     * True when the method needs a billing address + order lines on the create-payment (pay-later).
     */
    public static function requiresOrderData(string $methodId): bool
    {
        return in_array(strtolower($methodId), self::METHODS_REQUIRING_ORDER_DATA, true);
    }

    public static function supportsCountry(string $paymentId, string $country): bool
    {
        $countries = self::getSupportedCountries($paymentId);
        return $countries === [] || in_array(strtoupper($country), $countries, true);
    }
}
