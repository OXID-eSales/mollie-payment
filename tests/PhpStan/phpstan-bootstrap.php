<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 *
 * PHPStan bootstrap for the Mollie module.
 *
 * OXID generates virtual `{Core}_parent` classes at runtime — they don't exist on disk.
 * We stub them for PHPStan so level-max analysis of class extensions passes without
 * per-file suppressions. Keep this list minimal and justified.
 */

declare(strict_types=1);

if (!class_exists('OxidEsales\\Payments\\Mollie\\Core\\ViewConfig_parent', false)) {
    class_alias(
        \OxidEsales\Eshop\Core\ViewConfig::class,
        'OxidEsales\\Payments\\Mollie\\Core\\ViewConfig_parent',
    );
}
if (!class_exists('OxidEsales\\Payments\\Mollie\\Controller\\PaymentController_parent', false)) {
    class_alias(
        \OxidEsales\Eshop\Application\Controller\PaymentController::class,
        'OxidEsales\\Payments\\Mollie\\Controller\\PaymentController_parent',
    );
}
