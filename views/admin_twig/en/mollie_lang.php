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
    'MOLLIE_PAYMENT_TYPE' => 'Payment method',
    'MOLLIE_CONTRACT_STATE' => 'State',
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
    'SHOP_MODULE_sMollieLiveKey' => 'Live API key',
    'SHOP_MODULE_sMollieWebhookUrl' => 'Webhook URL override',
    'SHOP_MODULE_sMollieLogLevel' => 'Log level',
];
