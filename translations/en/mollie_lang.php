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

    // MOL-15: user-data field validation (payment-base ValidationBase, Mollie rules file).
    // %1$s = field label, %2$s = allowed-symbols description.
    'MOLLIE_VALIDATION_FIELD_INVALID' => 'The %1$s field is not valid. Allowed symbols are: %2$s',
    'MOLLIE_VALIDATION_REVIEW_ADDRESS' => 'Please review your address details.',
    'MOLLIE_VALIDATION_UNDERSTAND' => 'Understand',
    'MOLLIE_VALIDATION_INVALID_USER_DATA' => 'Some of your address details contain characters we cannot pass on to the payment provider. Please review your address details.',
    'MOLLIE_VALIDATION_CLASS_LETTERS' => 'letters',
    'MOLLIE_VALIDATION_CLASS_DIGITS' => 'digits',
    'MOLLIE_VALIDATION_CLASS_SPACES' => 'spaces',
    'MOLLIE_VALIDATION_LABEL_FIRSTNAME' => 'first name',
    'MOLLIE_VALIDATION_LABEL_LASTNAME' => 'last name',
    'MOLLIE_VALIDATION_LABEL_ADDITIONALINFO' => 'additional info',
    'MOLLIE_VALIDATION_LABEL_STREET' => 'street',
    'MOLLIE_VALIDATION_LABEL_HOUSENUMBER' => 'house number',
    'MOLLIE_VALIDATION_LABEL_POSTALCODE' => 'postal code',
    'MOLLIE_VALIDATION_LABEL_CITY' => 'city',
    'MOLLIE_VALIDATION_LABEL_COMPANY' => 'company',
    'MOLLIE_VALIDATION_LABEL_VATID' => 'VAT ID',
    'MOLLIE_VALIDATION_LABEL_PHONE' => 'phone',
    'MOLLIE_VALIDATION_LABEL_CELLPHONE' => 'cell phone',
    'MOLLIE_VALIDATION_LABEL_PERSONALPHONE' => 'personal phone',
    'MOLLIE_VALIDATION_LABEL_FAX' => 'fax',
    'MOLLIE_VALIDATION_LABEL_EMAIL' => 'e-mail address',

    // Shown when the Mollie checkout session cannot be started (was rendering as a raw key).
    'MOLLIE_CHECKOUT_UNAVAILABLE' => 'Payment via Mollie is not available right now. Please try again or choose another payment method.',
];
