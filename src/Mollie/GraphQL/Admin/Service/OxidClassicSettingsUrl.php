<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Mollie\Module;

/**
 * `{shopUrl}{adminDir}/index.php?cl=module_config&oxid=<module>&shp=<shopId>`: the classic module settings page,
 * where the module's settings are edited. No secret and no session token in it. The protected methods are the
 * Registry seams the tests override.
 */
class OxidClassicSettingsUrl implements ClassicSettingsUrlInterface
{
    private const DEFAULT_ADMIN_DIRECTORY = 'admin';

    public function url(): string
    {
        return rtrim($this->adminShopBaseUrl(), '/') . '/' . $this->adminDirectory()
            . '/index.php?cl=module_config&oxid=' . Module::MODULE_ID . '&shp=' . $this->shopId();
    }

    protected function adminShopBaseUrl(): string
    {
        // @phpstan-ignore-next-line OXID core: Registry::getConfig() is the sanctioned seam
        return Registry::getConfig()->getShopUrl(0, true);
    }

    protected function adminDirectory(): string
    {
        // @phpstan-ignore-next-line OXID core: Registry::getConfig() is the sanctioned seam
        $directory = Registry::getConfig()->getConfigParam('sAdminDir');

        return is_string($directory) && $directory !== '' ? $directory : self::DEFAULT_ADMIN_DIRECTORY;
    }

    protected function shopId(): int
    {
        // @phpstan-ignore-next-line OXID core: Registry::getConfig() is the sanctioned seam
        return (int) Registry::getConfig()->getShopId();
    }
}
