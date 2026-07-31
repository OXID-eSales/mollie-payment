<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

use OxidEsales\Eshop\Application\Controller\OrderController;
use OxidEsales\Eshop\Application\Controller\PaymentController;
use OxidEsales\Eshop\Core\ViewConfig;
use OxidEsales\Payments\Mollie\Controller\MollieOrderController;
use OxidEsales\Payments\Mollie\Controller\PaymentController as MolliePaymentController;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookController as MollieWebhookController;
use OxidEsales\Payments\Mollie\Component\Widget\MollieCheckoutFooter;
use OxidEsales\Payments\Mollie\Core\Events;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Core\ViewConfig as MollieViewConfig;
use OxidEsales\Payments\Mollie\Module;

$sMetadataVersion = '2.1';

$aModule = [
    'id' => Module::MODULE_ID,
    'title' => [
        'de' => 'Mollie Payment',
        'en' => 'Mollie Payment',
    ],
    'description' => [
        'de' => 'Mollie-Zahlungsintegration mit Smart Contracts für OXID eShop 7',
        'en' => 'Mollie payment integration with Smart Contracts for OXID eShop 7',
    ],
    'version' => '0.1.0',
    'author' => 'OXID eSales AG',
    'url' => 'https://github.com/OXID-eSales/mollie-payment',
    'email' => 'info@oxid-esales.com',
    'extend' => [
        ViewConfig::class => MollieViewConfig::class,
        PaymentController::class => MolliePaymentController::class,
        // OrderController::execute() (NOT PaymentController::execute(), which core doesn't
        // even declare) is where "Place order" finalizes the order — MollieOrderController
        // intercepts it here to redirect to Mollie's hosted checkout instead, mirroring
        // Stripe's `OrderController::class => StripeOrderController::class`. It is a pure
        // class-chain extension, NOT tagged `oxid.view_controller`: tagging a chain extension
        // as a service forces the DI compiler to reflect/autoload it, which re-enters the
        // OXID module class-chain build and fails with "Controller namespace duplication" on
        // activation.
        OrderController::class => MollieOrderController::class,
    ],
    'controllers' => [
        MollieDefinitions::WEBHOOK_CONTROLLER_ID => MollieWebhookController::class,
        // OPC footer widget (inline method selector + Mollie Components card). Loaded by
        // one-page-checkout when MolliePaymentHandler advertises footerWidget=molliecheckoutfooter.
        'molliecheckoutfooter' => MollieCheckoutFooter::class,
    ],
    'events' => [
        'onActivate' => Events::class . '::onActivate',
        'onDeactivate' => Events::class . '::onDeactivate',
    ],
    'templates' => [
        // Sprint 7 — body rendered inside payment-base's shared "Payment" admin tab. Both
        // aliases registered so `{% include %}` works with or without the `.html.twig` suffix
        // (mirrors PayPal's/Stripe's own panel template registration).
        '@oe_payments_mollie/admin/panel/mollie_panel' => 'views/twig/admin/panel/mollie_panel.html.twig',
        '@oe_payments_mollie/admin/panel/mollie_panel.html.twig' => 'views/twig/admin/panel/mollie_panel.html.twig',
    ],
    'settings' => [
        [
            'group' => 'MOLLIE_GENERAL',
            'name' => 'sMollieMode',
            'type' => 'select',
            'value' => MollieDefinitions::MODE_TEST,
            'position' => 10,
            'constraints' => MollieDefinitions::MODE_TEST . '|' . MollieDefinitions::MODE_LIVE,
        ],
        [
            'group' => 'MOLLIE_GENERAL',
            'name' => 'sMollieCaptureMode',
            'type' => 'select',
            'value' => MollieDefinitions::CAPTURE_MODE_AUTOMATIC,
            'position' => 20,
            'constraints' => MollieDefinitions::CAPTURE_MODE_AUTOMATIC . '|' . MollieDefinitions::CAPTURE_MODE_MANUAL,
        ],
        ['group' => 'MOLLIE_TEST_CONFIG', 'name' => 'sMollieTestKey', 'type' => 'str', 'value' => '', 'position' => 30],
        // IFRAME-04: Mollie website profile id (pfl_…), required by Mollie Components' JS
        // (`new Mollie(profileId, …)`) for inline card entry. Same id for test + live modes.
        ['group' => 'MOLLIE_GENERAL', 'name' => 'sMollieProfileId', 'type' => 'str', 'value' => '', 'position' => 25],
        ['group' => 'MOLLIE_LIVE_CONFIG', 'name' => 'sMollieLiveKey', 'type' => 'str', 'value' => '', 'position' => 40],
        ['group' => 'MOLLIE_WEBHOOKS', 'name' => 'sMollieWebhookUrl', 'type' => 'str', 'value' => '', 'position' => 50],
        [
            'group' => 'MOLLIE_LOGGING',
            'name' => 'sMollieLogLevel',
            'type' => 'select',
            'value' => MollieDefinitions::LOG_LEVEL_ERRORS,
            'position' => 60,
            'constraints' => MollieDefinitions::LOG_LEVEL_OFF . '|' . MollieDefinitions::LOG_LEVEL_ERRORS
                . '|' . MollieDefinitions::LOG_LEVEL_NORMAL . '|' . MollieDefinitions::LOG_LEVEL_DEBUG,
        ],
    ],
];
