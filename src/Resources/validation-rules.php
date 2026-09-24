<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

/**
 * Per-field character allow/block rules for data Mollie collects/sends at create-payment
 * (customer name, address, VAT id, phone, email). Loaded by payment-base's
 * FilesystemValidationRuleLoader for plugin id 'oe_payments_mollie'.
 *
 * UNICODE_LETTERS/NUMBERS/SPACES are class tokens interpreted by payment-base's
 * CharacterClass — copied from Stripe's Sprint 124 (STRP-129) rule set so German umlauts
 * (öäüß) and Polish letters (ł ą ę …) pass, not just ASCII.
 */
return [
    'fields' => [
        [
            'field' => 'firstName',
            'rules' => [
                'allow' => "UNICODE_LETTERS SPACES ' - .",
                'block' => ': ; < > { } [ ] ( ) | \\ / ~ ! @ # $ % ^ * = + " ? , & _',
            ],
        ],
        [
            'field' => 'lastName',
            'rules' => [
                'allow' => "UNICODE_LETTERS SPACES ' - .",
                'block' => ': ; < > { } [ ] ( ) | \\ / ~ ! @ # $ % ^ * = + " ? , & _',
            ],
        ],
        [
            'field' => 'street',
            'rules' => [
                'allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . , /",
                'block' => ': ; < > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'houseNumber',
            'rules' => [
                'allow' => 'NUMBERS UNICODE_LETTERS - /',
            ],
        ],
        [
            'field' => 'postalCode',
            'rules' => [
                'allow' => 'UNICODE_LETTERS NUMBERS SPACES -',
            ],
        ],
        [
            'field' => 'city',
            'rules' => [
                'allow' => "UNICODE_LETTERS SPACES ' - .",
                'block' => ': ; < > { } [ ] ( ) | \\ / ~ ! @ # $ % ^ * = + " ? , & _',
            ],
        ],
        [
            'field' => 'company',
            'rules' => [
                'allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . & ,",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'vatId',
            'rules' => [
                'allow' => 'UNICODE_LETTERS NUMBERS SPACES -',
            ],
        ],
        [
            'field' => 'additionalInfo',
            'rules' => [
                'allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . , / #",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'phone',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
        // Mollie collects the customer email for the create-payment call. '@' and '.' are
        // allowed literals (not class tokens) so a valid address can pass character-level
        // scrutiny; syntactic email validation stays OXID's own concern.
        [
            'field' => 'cellPhone',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
        [
            'field' => 'personalPhone',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
        [
            'field' => 'fax',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
        [
            'field' => 'email',
            'rules' => [
                'allow' => 'UNICODE_LETTERS NUMBERS @ . - _ +',
                'block' => ': ; < > { } [ ] ( ) | \\ / ~ ! # $ % ^ * = " ? , & \'',
            ],
        ],
        // MOL-15: admin free text sent to Mollie with a capture / refund (description fields).
        [
            'field' => 'captureReason',
            'rules' => [
                'allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . , / # ( ) :",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = + "',
            ],
        ],
        [
            'field' => 'refundDescription',
            'rules' => [
                'allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . , / # ( ) :",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = + "',
            ],
        ],
    ],
];
