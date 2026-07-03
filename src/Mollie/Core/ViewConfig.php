<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Core;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieMethodDto;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\PaymentMethodListServiceInterface;

/**
 * Mollie ViewConfig extension.
 *
 * Registered in metadata so the storefront can query Mollie-specific view data.
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
     * Enabled Mollie methods for the storefront selector (Sprint 7). Protected seams below let
     * unit tests exercise this without an OXID/DI bootstrap — mirrors the module's other
     * testable-subclass patterns (e.g. `ModuleConfigurationService::readSetting()`).
     *
     * @return list<MollieMethodDto>
     */
    public function getMollieActiveMethods(): array
    {
        return $this->mollieMethodListService()->listActiveMethods($this->mollieActiveCurrency());
    }

    protected function mollieActiveCurrency(): string
    {
        // @phpstan-ignore-next-line OXID core: Registry::getConfig() virtual parent
        $currency = Registry::getConfig()->getActShopCurrencyObject();
        if (is_object($currency) && isset($currency->name) && is_string($currency->name) && $currency->name !== '') {
            return $currency->name;
        }

        return MollieDefinitions::getSupportedCurrencies(MollieDefinitions::PAYMENT_ID)[0] ?? 'EUR';
    }

    protected function mollieMethodListService(): PaymentMethodListServiceInterface
    {
        /** @phpstan-ignore-next-line container.notFound */
        return ContainerFactory::getInstance()->getContainer()->get(PaymentMethodListServiceInterface::class);
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
