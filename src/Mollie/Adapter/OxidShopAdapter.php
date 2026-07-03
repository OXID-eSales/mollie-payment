<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;

/**
 * OXID implementation of payment-base ShopAdapterInterface for the Mollie module.
 *
 * Provides the narrow shop-context values the checkout flow and URL builders need. isTestMode()
 * reflects the Mollie module mode (test/live), not the shop debug flag — so "test" means "using a
 * Mollie test_ key". Ported from PayPal.
 */
class OxidShopAdapter implements ShopAdapterInterface
{
    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config,
    ) {
    }

    public function translateString(string $languageConstant, ?string $languageAbbr = null): string
    {
        $lang = Registry::getLang();

        if ($languageAbbr !== null) {
            $languageId = $this->getLanguageIdByAbbr($languageAbbr);
            /** @var string|array<string> $result */
            $result = $lang->translateString($languageConstant, $languageId);
            return is_array($result) ? (string) reset($result) : $result;
        }

        /** @var string|array<string> $result */
        $result = $lang->translateString($languageConstant);
        return is_array($result) ? (string) reset($result) : $result;
    }

    public function getCurrentLanguageAbbr(): string
    {
        return (string) Registry::getLang()->getLanguageAbbr();
    }

    public function getActiveLanguageId(): int
    {
        return (int) Registry::getLang()->getBaseLanguage();
    }

    public function getShopId(): string
    {
        return (string) Registry::getConfig()->getShopId();
    }

    public function getShopUrl(): string
    {
        return (string) Registry::getConfig()->getCurrentShopUrl();
    }

    public function getShopName(): string
    {
        $shop = Registry::getConfig()->getActiveShop();
        /** @phpstan-ignore-next-line OXID core: magic property oxshops__oxname->value */
        return $shop->oxshops__oxname->value ?? 'OXID eShop';
    }

    public function getShopCurrency(): string
    {
        $currency = Registry::getConfig()->getActShopCurrencyObject();
        $name = $currency->name ?? 'EUR';
        return is_scalar($name) ? (string) $name : 'EUR';
    }

    public function isTestMode(): bool
    {
        return $this->config->isTestMode();
    }

    public function getAdapterName(): string
    {
        return 'oxid-mollie';
    }

    private function getLanguageIdByAbbr(string $abbr): ?int
    {
        $languages = Registry::getLang()->getLanguageArray();
        foreach ($languages as $language) {
            if (!is_object($language)) {
                continue;
            }
            /** @var object{abbr?: string, id?: int} $language */
            if (isset($language->abbr) && $language->abbr === $abbr) {
                return isset($language->id) ? (int) $language->id : null;
            }
        }
        return null;
    }
}
