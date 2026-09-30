<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

$sLangName = 'English';

/** @var array<string, string> $aLang */
$aLang = [
    'charset' => 'UTF-8',

    // Sprint 7 — Mollie panel body inside the shared Payment admin tab.
    'MOLLIE_ORDER_PAYMENT_DETAILS' => 'Payment details',
    'MOLLIE_ORDER_NUMBER' => 'Order number',
    'MOLLIE_CONTRACT_ID' => 'Contract ID',
    'MOLLIE_PAYMENT_ID' => 'Mollie payment ID',
    'MOLLIE_PAYMENT_TYPE' => 'Shop payment method',
    // MOL-10: the OXID contract state, named as such (the Help table explains the values).
    'MOLLIE_CONTRACT_STATE' => 'OXID Contract Status',
    'MOLLIE_CAPTURED_AMOUNT' => 'Captured',
    'MOLLIE_REFUNDED_AMOUNT' => 'Refunded',
    'MOLLIE_NO_CONTRACT' => 'This order was not processed through Mollie.',

    'MOLLIE_TRANSACTION_HISTORY' => 'Transaction history',
    'MOLLIE_TRANSACTION_TYPE' => 'Type',
    'MOLLIE_TRANSACTION_ID' => 'ID',
    'MOLLIE_TRANSACTION_STATUS' => 'Status',
    'MOLLIE_TRANSACTION_DATE' => 'Date',
    'MOLLIE_AMOUNT' => 'Amount',

    // Predefined refund / cancel reasons (mirrors Stripe's reason dropdowns).
    'MOLLIE_PLEASE_SELECT' => '— Please select —',
    'MOLLIE_REASON_REQUESTED_BY_CUSTOMER' => 'Requested by customer',
    'MOLLIE_REASON_DUPLICATE' => 'Duplicate',
    'MOLLIE_REASON_FRAUDULENT' => 'Fraudulent',
    'MOLLIE_REASON_ABANDONED' => 'Abandoned',

    'MOLLIE_CAPTURE' => 'Capture',
    'MOLLIE_CAPTURABLE_AMOUNT' => 'Capturable amount',
    'MOLLIE_CAPTURE_REASON' => 'Reason',
    'MOLLIE_CAPTURE_SUBMIT' => 'Execute capture',
    'MOLLIE_CAPTURE_CONFIRM' => 'Capture the authorized amount via Mollie?',

    'MOLLIE_REFUND' => 'Refund',
    'MOLLIE_REFUNDABLE_AMOUNT' => 'Refundable amount',
    'MOLLIE_REFUND_REASON' => 'Reason',
    'MOLLIE_REFUND_DESCRIPTION' => 'Description (optional)',
    'MOLLIE_REFUND_DESCRIPTION_PLACEHOLDER' => 'e.g. reason for the refund (max. 140 characters)',
    'MOLLIE_REFUND_SUBMIT' => 'Execute refund',
    'MOLLIE_REFUND_CONFIRM' => 'Refund the captured amount via Mollie?',

    'MOLLIE_CANCEL' => 'Cancel authorization',
    'MOLLIE_CANCEL_REASON' => 'Reason',
    'MOLLIE_CANCEL_SUBMIT' => 'Cancel authorization',
    'MOLLIE_CANCEL_CONFIRM' => 'Cancel the uncaptured authorization via Mollie?',

    // Sprint 7 Story 3 — admin amount validation messages
    // (AdminAmountValidationMessageFormatter).
    'MOLLIE_ADMIN_AMOUNT_MALFORMED' => 'The amount is not a valid number.',
    'MOLLIE_ADMIN_AMOUNT_NOT_POSITIVE' => 'The amount must be greater than zero.',
    'MOLLIE_ADMIN_AMOUNT_PRECISION' => 'The amount may only have up to two decimal places.',
    'MOLLIE_ADMIN_AMOUNT_EXCEEDS_BOUND' => 'The amount exceeds the available balance.',
    'MOLLIE_ADMIN_AMOUNT_INVALID' => 'The amount is invalid.',

    // Module configuration — group headings
    'SHOP_MODULE_GROUP_MOLLIE_GENERAL' => 'General',
    'SHOP_MODULE_GROUP_MOLLIE_TEST_CONFIG' => 'Test credentials',
    'SHOP_MODULE_GROUP_MOLLIE_LIVE_CONFIG' => 'Live credentials',
    'SHOP_MODULE_GROUP_MOLLIE_WEBHOOKS' => 'Webhooks',
    'SHOP_MODULE_GROUP_MOLLIE_LOGGING' => 'Logging',

    // Module configuration — settings
    'SHOP_MODULE_sMollieMode' => 'API mode',
    'SHOP_MODULE_sMollieMode_test' => 'Test',
    'SHOP_MODULE_sMollieMode_live' => 'Live',
    'SHOP_MODULE_sMollieCaptureMode' => 'Capture mode',
    'SHOP_MODULE_sMollieCaptureMode_automatic' => 'Automatic',
    'SHOP_MODULE_sMollieCaptureMode_manual' => 'Manual',
    'SHOP_MODULE_sMollieTestKey' => 'Test API key',
    'SHOP_MODULE_sMollieProfileId' => 'Website profile ID (pfl_…) — required for inline card (Mollie Components)',
    'SHOP_MODULE_sMollieLiveKey' => 'Live API key',
    'SHOP_MODULE_sMollieWebhookUrl' => 'Webhook URL override',
    'SHOP_MODULE_sMollieLogLevel' => 'Log level',
    // Option labels for the select. Without these OXID renders the literal text
    // "ERROR: Translation not found" inside each <option> — which is what it did until now.
    // "debug" is the only level that also serves the unminified storefront bundle and prints to the
    // browser console, so it is labelled as a development setting rather than just a louder log.
    'SHOP_MODULE_sMollieLogLevel_off' => 'Off — log nothing',
    'SHOP_MODULE_sMollieLogLevel_errors' => 'Errors only (recommended)',
    'SHOP_MODULE_sMollieLogLevel_normal' => 'Normal — errors and payment events',
    'SHOP_MODULE_sMollieLogLevel_debug' => 'Debug — verbose, includes browser console output',

    // Sprint 10 — the reveal/hide toggle on masked settings. Both strings are the button's
    // aria-label, swapped as it flips, so they are read by a screen reader rather than shown on
    // screen. Deliberately generic: the toggle sits beside the API keys AND the website profile id,
    // so "Reveal API key" would be wrong on one of them.
    'MOLLIE_REVEAL_VALUE' => 'Reveal value',
    'MOLLIE_HIDE_VALUE' => 'Hide value',

    // Sprint 136 (STRP-TBD): the method the customer actually paid with, read
    // from the live Mollie payment. The row above is the shop's payment-method
    // id and reads 'mollie_payment' for every Mollie order.
    'MOLLIE_PAYMENT_METHOD_USED' => 'Payment method used',
    'MOLLIE_PAYMENT_METHOD_CREDITCARD' => 'Credit card',
    'MOLLIE_PAYMENT_METHOD_APPLE_PAY' => 'Apple Pay',
    'MOLLIE_PAYMENT_METHOD_GOOGLE_PAY' => 'Google Pay',
    'MOLLIE_PAYMENT_METHOD_PAYPAL' => 'PayPal',
    'MOLLIE_PAYMENT_METHOD_KLARNA' => 'Klarna',
    'MOLLIE_PAYMENT_METHOD_IN3' => 'in3',
    'MOLLIE_PAYMENT_METHOD_RIVERTY' => 'Riverty',
    'MOLLIE_PAYMENT_METHOD_BILLIE' => 'Billie',
    'MOLLIE_PAYMENT_METHOD_ALMA' => 'Alma',
    'MOLLIE_PAYMENT_METHOD_DIRECTDEBIT' => 'SEPA direct debit',
    'MOLLIE_PAYMENT_METHOD_BANKTRANSFER' => 'Bank transfer',
    'MOLLIE_PAYMENT_METHOD_PAYBYBANK' => 'Pay by Bank',
    'MOLLIE_PAYMENT_METHOD_TRUSTLY' => 'Trustly',
    'MOLLIE_PAYMENT_METHOD_IDEAL' => 'iDEAL',
    'MOLLIE_PAYMENT_METHOD_BANCONTACT' => 'Bancontact',
    'MOLLIE_PAYMENT_METHOD_SOFORT' => 'Sofort',
    'MOLLIE_PAYMENT_METHOD_EPS' => 'EPS',
    'MOLLIE_PAYMENT_METHOD_PRZELEWY24' => 'Przelewy24',
    'MOLLIE_PAYMENT_METHOD_BELFIUS' => 'Belfius',
    'MOLLIE_PAYMENT_METHOD_KBC' => 'KBC/CBC',
    'MOLLIE_PAYMENT_METHOD_BLIK' => 'BLIK',
    'MOLLIE_PAYMENT_METHOD_MBWAY' => 'MB WAY',
    'MOLLIE_PAYMENT_METHOD_MULTIBANCO' => 'Multibanco',
    'MOLLIE_PAYMENT_METHOD_PAYCONIQ' => 'Payconiq',
    'MOLLIE_PAYMENT_METHOD_SATISPAY' => 'Satispay',
    'MOLLIE_PAYMENT_METHOD_TWINT' => 'TWINT',
    'MOLLIE_PAYMENT_METHOD_GIFTCARD' => 'Gift card',
    'MOLLIE_PAYMENT_METHOD_VOUCHER' => 'Voucher',
    'MOLLIE_PAYMENT_METHOD_POINTOFSALE' => 'Point of sale',

    // MOL-15: admin free text (capture reason / refund description) validated with the checkout rules.
    'MOLLIE_VALIDATION_FIELD_INVALID' => 'The %1$s field is not valid. Allowed symbols are: %2$s',
    'MOLLIE_VALIDATION_CLASS_LETTERS' => 'letters',
    'MOLLIE_VALIDATION_CLASS_DIGITS' => 'digits',
    'MOLLIE_VALIDATION_CLASS_SPACES' => 'spaces',
    'MOLLIE_VALIDATION_LABEL_CAPTUREREASON' => 'capture reason',
    'MOLLIE_VALIDATION_LABEL_REFUNDDESCRIPTION' => 'refund description',

    // MOL-10: "Help" group at the end of the module settings — contract state ↔ Mollie payment status.
    'MOLLIE_HELP' => 'Help',
    'MOLLIE_HELP_CONTRACT_STATES_INTRO' => 'The Payment tab of an order shows the OXID Contract Status: the state of the payment contract the module keeps for that order. This table explains each state and the Mollie payment status it corresponds to.',
    'MOLLIE_HELP_COL_CONTRACT_STATE' => 'OXID Contract Status',
    'MOLLIE_HELP_COL_MEANING' => 'Meaning',
    'MOLLIE_HELP_COL_MOLLIE_STATUS' => 'Mollie payment status',
    'MOLLIE_HELP_MOLLIE_NONE' => 'none (shop-internal)',
    'MOLLIE_HELP_STATE_NOT_FINISHED' => 'The order row exists and the customer was redirected to Mollie; nothing has been committed yet.',
    'MOLLIE_HELP_STATE_PENDING' => 'The customer has committed; the payment network has not confirmed yet.',
    'MOLLIE_HELP_STATE_AUTHORIZED' => 'Funds are reserved; the merchant still has to capture them.',
    'MOLLIE_HELP_STATE_READY_TO_COMMIT' => 'The money has been taken; the order is not committed in the shop yet.',
    'MOLLIE_HELP_STATE_COMMITTED_FULFILLED' => 'Shop-internal only: the order is committed (paid) and finally fulfilled.',
    'MOLLIE_HELP_STATE_CANCELLED' => 'Someone stopped the payment (customer, merchant or Mollie).',
    'MOLLIE_HELP_STATE_EXPIRED' => 'The payment window ran out before the customer completed the payment.',
    'MOLLIE_HELP_STATE_FAILED' => 'The payment attempt was rejected.',
];
