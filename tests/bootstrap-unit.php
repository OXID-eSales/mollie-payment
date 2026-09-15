<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Bootstrap for the standalone unit suite — no shop, no database.
 *
 * The module's own vendor/ carries oxideshop-ce plus the unified namespace
 * generator, so `OxidEsales\Eshop\*` resolves from there. Missing are the
 * `*_parent` classes, which OXID's ModuleChainsGenerator only creates at module
 * activation, the shop's global functions, and a Config that does not go to the
 * database. Unit tests never instantiate a real parent — they use testable
 * subclasses — so a minimal stub is enough for the extending class to load.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// The shop bootstrap normally defines this; Config and friends read it.
if (!defined('OX_BASE_PATH')) {
    define('OX_BASE_PATH', realpath(__DIR__ . '/../vendor/oxid-esales/oxideshop-ce/source') . DIRECTORY_SEPARATOR);
}

// oxideshop-ce ships oxNew(), getLogger() and friends as plain function
// definitions. Registry and the shop classes call them, so the standalone
// suite needs them declared — these files define functions only, they boot
// nothing.
foreach (
    [
        '/../vendor/oxid-esales/oxideshop-ce/source/overridablefunctions.php',
        '/../vendor/oxid-esales/oxideshop-ce/source/oxfunctions.php',
    ] as $functionFile
) {
    $path = __DIR__ . $functionFile;
    if (file_exists($path)) {
        require_once $path;
    }
}

if (!class_exists(\OxidEsales\Payments\Mollie\Controller\PaymentController_parent::class, false)) {
    eval(
        'namespace OxidEsales\\Payments\\Mollie\\Controller; '
        . 'class PaymentController_parent { '
        . '  public function __construct() {} '
        . '  public function init(): void {} '
        . '  public function render() { return ""; } '
        . '  public function validatePayment() { return null; } '
        . '  public function getUser() { return null; } '
        . '  public function getBasket() { return false; } '
        . '  public function addTplParam($name, $value): void {} '
        . '}'
    );
}

if (!class_exists(\OxidEsales\Payments\Mollie\Controller\MollieOrderController_parent::class, false)) {
    eval(
        'namespace OxidEsales\\Payments\\Mollie\\Controller; '
        . 'class MollieOrderController_parent { '
        . '  public function __construct() {} '
        . '  public function init(): void {} '
        . '  public function render() { return ""; } '
        . '  public function execute() { return null; } '
        . '  public function getUser() { return null; } '
        . '  public function getBasket() { return false; } '
        . '  public function getPayment() { return false; } '
        . '  public function addTplParam($name, $value): void {} '
        . '}'
    );
}

if (!class_exists(\OxidEsales\Payments\Mollie\Core\ViewConfig_parent::class, false)) {
    eval(
        'namespace OxidEsales\\Payments\\Mollie\\Core; '
        . 'class ViewConfig_parent { '
        . '  public function __construct() {} '
        . '  protected function getServerName(): string { return ""; } '
        . '  public function getModuleUrl($module, $file = ""): string { return ""; } '
        . '}'
    );
}

/**
 * A shop Config that answers from memory instead of from the database — see the
 * same stub in stripe-wallet for why: Registry::getConfig() is reachable from
 * display-only paths, and the real Config resolves those through
 * DatabaseProvider.
 */
\OxidEsales\Eshop\Core\Registry::set(
    \OxidEsales\Eshop\Core\Config::class,
    new class extends \OxidEsales\Eshop\Core\Config {
        public function getConfigParam($name, $default = null)
        {
            // Without these two the shop code takes the literal placeholders
            // from config.inc.php.dist and creates directories called
            // "<sCompileDir>" / "<sShopDir>" in the working directory.
            return match ($name) {
                'sCompileDir' => sys_get_temp_dir() . '/oxid-unit-tests',
                'sShopDir'    => OX_BASE_PATH,
                default       => $default,
            };
        }

        public function getShopId()
        {
            return 1;
        }

        public function isProductiveMode()
        {
            return true;
        }

        public function getActShopCurrencyObject()
        {
            return false;
        }
    }
);
