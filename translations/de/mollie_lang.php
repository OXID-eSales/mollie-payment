<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

$sLangName = "Deutsch";

$aLang = [
    'charset' => 'UTF-8',

    // IFRAME-04: Inline-Methodenauswahl + Karteneingabe (Mollie Components) auf der Bestellseite.
    'MOLLIE_CHOOSE_METHOD' => 'Zahlungsart wählen',
    'MOLLIE_CARD_NUMBER' => 'Kartennummer',
    'MOLLIE_CARD_HOLDER' => 'Karteninhaber',
    'MOLLIE_CARD_EXPIRY' => 'Ablaufdatum',
    'MOLLIE_CARD_CVC' => 'Prüfnummer (CVC)',

    // Meldungen nach der Rückkehr von Mollie (Storefront).
    'MOLLIE_RETURN_PENDING' => 'Ihre Zahlung wird verarbeitet. Wir bestätigen Ihre Bestellung, sobald Mollie die Zahlung abgeschlossen hat.',
    'MOLLIE_RETURN_RETURN_NOT_FINALISED' => 'Ihre Zahlung konnte nicht abgeschlossen werden. Bitte versuchen Sie es erneut oder wählen Sie eine andere Zahlungsart.',
    'MOLLIE_RETURN_MISSING_TOKEN' => 'Ihre Zahlungssitzung konnte nicht verifiziert werden. Bitte versuchen Sie es erneut.',
    'MOLLIE_RETURN_INVALID_TOKEN' => 'Ihre Zahlungssitzung konnte nicht verifiziert werden. Bitte versuchen Sie es erneut.',
    'MOLLIE_RETURN_UNKNOWN_CONTRACT' => 'Ihre Zahlungssitzung wurde nicht gefunden. Bitte versuchen Sie es erneut.',
    'MOLLIE_RETURN_RETURN_SERVICE_UNAVAILABLE' => 'Die Zahlung konnte derzeit nicht verarbeitet werden. Bitte versuchen Sie es in Kürze erneut.',

    // MOL-15: Validierung der Adressfelder (payment-base ValidationBase, Mollie-Regeldatei).
    'MOLLIE_VALIDATION_FIELD_INVALID' => 'Das Feld %1$s ist ungültig. Erlaubte Zeichen: %2$s',
    'MOLLIE_VALIDATION_REVIEW_ADDRESS' => 'Bitte überprüfen Sie Ihre Adressdaten.',
    'MOLLIE_VALIDATION_UNDERSTAND' => 'Verstanden',
    'MOLLIE_VALIDATION_INVALID_USER_DATA' => 'Einige Ihrer Adressdaten enthalten Zeichen, die wir nicht an den Zahlungsanbieter übergeben können. Bitte überprüfen Sie Ihre Adressdaten.',
    'MOLLIE_VALIDATION_CLASS_LETTERS' => 'Buchstaben',
    'MOLLIE_VALIDATION_CLASS_DIGITS' => 'Ziffern',
    'MOLLIE_VALIDATION_CLASS_SPACES' => 'Leerzeichen',
    'MOLLIE_VALIDATION_LABEL_FIRSTNAME' => 'Vorname',
    'MOLLIE_VALIDATION_LABEL_LASTNAME' => 'Nachname',
    'MOLLIE_VALIDATION_LABEL_ADDITIONALINFO' => 'Adresszusatz',
    'MOLLIE_VALIDATION_LABEL_STREET' => 'Straße',
    'MOLLIE_VALIDATION_LABEL_HOUSENUMBER' => 'Hausnummer',
    'MOLLIE_VALIDATION_LABEL_POSTALCODE' => 'Postleitzahl',
    'MOLLIE_VALIDATION_LABEL_CITY' => 'Stadt',
    'MOLLIE_VALIDATION_LABEL_COMPANY' => 'Firma',
    'MOLLIE_VALIDATION_LABEL_VATID' => 'USt-IdNr.',
    'MOLLIE_VALIDATION_LABEL_PHONE' => 'Telefon',
    'MOLLIE_VALIDATION_LABEL_CELLPHONE' => 'Mobiltelefon',
    'MOLLIE_VALIDATION_LABEL_PERSONALPHONE' => 'Telefon (privat)',
    'MOLLIE_VALIDATION_LABEL_FAX' => 'Fax',
    'MOLLIE_VALIDATION_LABEL_EMAIL' => 'E-Mail-Adresse',

    'MOLLIE_CHECKOUT_UNAVAILABLE' => 'Die Zahlung über Mollie ist derzeit nicht verfügbar. Bitte versuchen Sie es erneut oder wählen Sie eine andere Zahlungsart.',
];
