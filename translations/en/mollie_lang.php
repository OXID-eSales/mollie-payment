<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

$sLangName = "English";

$aLang = [
    'charset' => 'UTF-8',

    // IFRAME-04: inline method selection + card entry (Mollie Components) on the order page.
    'MOLLIE_CHOOSE_METHOD' => 'Choose your payment method',
    'MOLLIE_CARD_NUMBER' => 'Card number',
    'MOLLIE_CARD_HOLDER' => 'Cardholder name',
    'MOLLIE_CARD_EXPIRY' => 'Expiry date',
    'MOLLIE_CARD_CVC' => 'CVC',

    // Post-checkout return messages (shown on the storefront after returning from Mollie).
    'MOLLIE_RETURN_PENDING' => 'Your payment is being processed. We will confirm your order as soon as Mollie completes the payment.',
    'MOLLIE_RETURN_RETURN_NOT_FINALISED' => 'Your payment could not be completed. Please try again or choose another payment method.',
    'MOLLIE_RETURN_MISSING_TOKEN' => 'Your payment session could not be verified. Please try again.',
    'MOLLIE_RETURN_INVALID_TOKEN' => 'Your payment session could not be verified. Please try again.',
    'MOLLIE_RETURN_UNKNOWN_CONTRACT' => 'Your payment session could not be found. Please try again.',
    'MOLLIE_RETURN_RETURN_SERVICE_UNAVAILABLE' => 'Payment could not be processed right now. Please try again shortly.',
];
