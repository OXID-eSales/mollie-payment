<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Core;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;

/**
 * Mollie ViewConfig extension.
 *
 * Registered in metadata so the storefront can query Mollie-specific view data. There is no
 * method-selector data here: the storefront shows a single "Mollie" payment option, and Mollie's
 * own hosted checkout page presents the individual methods (iDEAL, cards, …) after redirect.
 *
 * @phpstan-ignore class.notFound
 */
class ViewConfig extends ViewConfig_parent
{
    public function getMollieModuleId(): string
    {
        return MollieDefinitions::MODULE_ID;
    }

    /**
     * Drives the frontend Stimulus controller's `debugValue` (see
     * `resources/js/controllers/mollie_checkout_controller.js`) from the merchant-configured
     * `sMollieLogLevel`, mirroring Stripe's runtime-flag-driven frontend logging: a redeploy is
     * never required to see (or silence) the on-page debug console output.
     */
    public function isMollieDebugLoggingEnabled(): bool
    {
        return $this->mollieConfigService()?->isFrontendDebugEnabled() ?? false;
    }

    protected function mollieConfigService(): ?ModuleConfigurationServiceInterface
    {
        /** @phpstan-ignore-next-line container.notFound */
        $service = ContainerFactory::getInstance()->getContainer()->get(ModuleConfigurationServiceInterface::class);

        return $service instanceof ModuleConfigurationServiceInterface ? $service : null;
    }
}
