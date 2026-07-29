<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

$sLangName = 'Deutsch';

/** @var array<string, string> $aLang */
$aLang = [
    'charset' => 'UTF-8',

    'MOLLIE_ORDER_PAYMENT_DETAILS' => 'Zahlungsdetails',
    'MOLLIE_ORDER_NUMBER' => 'Bestellnummer',
    'MOLLIE_CONTRACT_ID' => 'Vertrags-ID',
    'MOLLIE_PAYMENT_ID' => 'Mollie-Zahlungs-ID',
    'MOLLIE_PAYMENT_TYPE' => 'Zahlungsart',
    'MOLLIE_CONTRACT_STATE' => 'Status',
    'MOLLIE_CAPTURED_AMOUNT' => 'Erfasst',
    'MOLLIE_REFUNDED_AMOUNT' => 'Erstattet',
    'MOLLIE_NO_CONTRACT' => 'Diese Bestellung wurde nicht über Mollie abgewickelt.',

    'MOLLIE_TRANSACTION_HISTORY' => 'Transaktionsverlauf',
    'MOLLIE_TRANSACTION_TYPE' => 'Typ',
    'MOLLIE_TRANSACTION_ID' => 'ID',
    'MOLLIE_TRANSACTION_STATUS' => 'Status',
    'MOLLIE_TRANSACTION_DATE' => 'Datum',
    'MOLLIE_AMOUNT' => 'Betrag',

    // Vordefinierte Gründe für Erstattung / Stornierung (analog zu Stripe).
    'MOLLIE_PLEASE_SELECT' => '— Bitte wählen —',
    'MOLLIE_REASON_REQUESTED_BY_CUSTOMER' => 'Vom Kunden angefordert',
    'MOLLIE_REASON_DUPLICATE' => 'Doppelbuchung',
    'MOLLIE_REASON_FRAUDULENT' => 'Betrug',
    'MOLLIE_REASON_ABANDONED' => 'Abgebrochen',

    'MOLLIE_CAPTURE' => 'Erfassen',
    'MOLLIE_CAPTURABLE_AMOUNT' => 'Erfassbarer Betrag',
    'MOLLIE_CAPTURE_REASON' => 'Grund',
    'MOLLIE_CAPTURE_SUBMIT' => 'Erfassung ausführen',
    'MOLLIE_CAPTURE_CONFIRM' => 'Den autorisierten Betrag über Mollie erfassen?',

    'MOLLIE_REFUND' => 'Erstattung',
    'MOLLIE_REFUNDABLE_AMOUNT' => 'Erstattbarer Betrag',
    'MOLLIE_REFUND_REASON' => 'Grund',
    'MOLLIE_REFUND_DESCRIPTION' => 'Beschreibung (optional)',
    'MOLLIE_REFUND_DESCRIPTION_PLACEHOLDER' => 'z. B. Grund für die Erstattung (max. 140 Zeichen)',
    'MOLLIE_REFUND_SUBMIT' => 'Erstattung ausführen',
    'MOLLIE_REFUND_CONFIRM' => 'Den erfassten Betrag über Mollie erstatten?',

    'MOLLIE_CANCEL' => 'Autorisierung stornieren',
    'MOLLIE_CANCEL_REASON' => 'Grund',
    'MOLLIE_CANCEL_SUBMIT' => 'Autorisierung stornieren',
    'MOLLIE_CANCEL_CONFIRM' => 'Die nicht erfasste Autorisierung über Mollie stornieren?',

    'MOLLIE_ADMIN_AMOUNT_MALFORMED' => 'Der Betrag ist keine gültige Zahl.',
    'MOLLIE_ADMIN_AMOUNT_NOT_POSITIVE' => 'Der Betrag muss größer als null sein.',
    'MOLLIE_ADMIN_AMOUNT_PRECISION' => 'Der Betrag darf höchstens zwei Nachkommastellen haben.',
    'MOLLIE_ADMIN_AMOUNT_EXCEEDS_BOUND' => 'Der Betrag überschreitet den verfügbaren Saldo.',
    'MOLLIE_ADMIN_AMOUNT_INVALID' => 'Der Betrag ist ungültig.',

    'SHOP_MODULE_GROUP_MOLLIE_GENERAL' => 'Allgemein',
    'SHOP_MODULE_GROUP_MOLLIE_TEST_CONFIG' => 'Test-Zugangsdaten',
    'SHOP_MODULE_GROUP_MOLLIE_LIVE_CONFIG' => 'Live-Zugangsdaten',
    'SHOP_MODULE_GROUP_MOLLIE_WEBHOOKS' => 'Webhooks',
    'SHOP_MODULE_GROUP_MOLLIE_LOGGING' => 'Protokollierung',

    'SHOP_MODULE_sMollieMode' => 'API-Modus',
    'SHOP_MODULE_sMollieMode_test' => 'Test',
    'SHOP_MODULE_sMollieMode_live' => 'Live',
    'SHOP_MODULE_sMollieCaptureMode' => 'Erfassungsmodus',
    'SHOP_MODULE_sMollieCaptureMode_automatic' => 'Automatisch',
    'SHOP_MODULE_sMollieCaptureMode_manual' => 'Manuell',
    'SHOP_MODULE_sMollieTestKey' => 'Test-API-Schlüssel',
    'SHOP_MODULE_sMollieProfileId' => 'Website-Profil-ID (pfl_…) — erforderlich für Inline-Karte (Mollie Components)',
    'SHOP_MODULE_sMollieLiveKey' => 'Live-API-Schlüssel',
    'SHOP_MODULE_sMollieWebhookUrl' => 'Webhook-URL (überschreiben)',
    'SHOP_MODULE_sMollieLogLevel' => 'Protokollstufe',
];
