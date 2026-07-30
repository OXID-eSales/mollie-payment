<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Core;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\PaymentMethodListServiceInterface;
use Throwable;

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

    /**
     * IFRAME-04: true when the merchant enabled the payment-base "Use iframe instead of checkout
     * button" flag AND a Mollie website profile id is configured. Only then does the order page
     * offer inline card entry (Mollie Components); otherwise the classic redirect flow is used.
     */
    public function isMollieInlineCardEnabled(): bool
    {
        if ($this->getMollieProfileId() === '') {
            return false;
        }

        return $this->mollieIframeSettings()?->isEnabled() ?? false;
    }

    public function getMollieProfileId(): string
    {
        return $this->mollieConfigService()?->getProfileId() ?? '';
    }

    /**
     * IFRAME-04: the enabled Mollie methods (iDEAL, credit card, PayPal, …) to offer inline on the
     * order page. Each entry is `{id, name, image}`. "creditcard" is rendered with inline Components
     * card fields; every other method redirects to Mollie on submit. Returns [] on any failure
     * (the template then falls back to the classic redirect button).
     *
     * @return list<array{id: string, name: string, image: string|null}>
     */
    public function getMollieMethods(): array
    {
        try {
            $service = ContainerFactory::getInstance()->getContainer()
                ->get(PaymentMethodListServiceInterface::class);
            if (!$service instanceof PaymentMethodListServiceInterface) {
                return [];
            }
            $methods = $service->listActiveMethods($this->mollieActiveCurrency(), $this->mollieBillingCountryIso());

            return array_map(
                static fn ($m): array => ['id' => $m->id, 'name' => $m->description, 'image' => $m->imageUrl],
                $methods,
            );
        } catch (Throwable) {
            return [];
        }
    }

    protected function mollieActiveCurrency(): string
    {
        $currency = Registry::getConfig()->getActShopCurrencyObject();
        $name = is_object($currency) && isset($currency->name) ? $currency->name : '';

        return is_string($name) && $name !== '' ? $name : 'EUR';
    }

    /**
     * Best-effort ISO-2 billing country of the active user, passed to Mollie's country filter so
     * country-specific methods (e.g. iDEAL) are offered appropriately. Null when unavailable.
     */
    protected function mollieBillingCountryIso(): ?string
    {
        try {
            $user = Registry::getSession()->getUser();
            if (!is_object($user)) {
                return null;
            }
            $countryId = $user->getFieldData('oxcountryid');
            $countryId = is_scalar($countryId) ? (string) $countryId : '';
            if ($countryId === '') {
                return null;
            }
            /** @var \OxidEsales\Eshop\Application\Model\Country $country — oxNew model factory */
            $country = oxNew(\OxidEsales\Eshop\Application\Model\Country::class);
            $country->load($countryId);
            $iso = $country->getFieldData('oxisoalpha2');

            return is_scalar($iso) && (string) $iso !== '' ? (string) $iso : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function isMollieTestMode(): bool
    {
        return $this->mollieConfigService()?->isTestMode() ?? true;
    }

    /**
     * Mollie Components' JS expects a locale like `en_US` / `de_DE`. Map from the active shop
     * language; default to English for any non-German language.
     */
    public function getMollieComponentsLocale(): string
    {
        $abbr = (string) Registry::getLang()->getLanguageAbbr();

        return str_starts_with($abbr, 'de') ? 'de_DE' : 'en_US';
    }

    /**
     * Served storefront bundle: the dev (unminified) build when debug logging is on, else the
     * minified production bundle. Mirrors Stripe's ViewConfig::getStripeJsPath().
     */
    public function getMollieJsPath(): string
    {
        return $this->isMollieDebugLoggingEnabled() ? 'js/mollie-frontend.js' : 'js/mollie-frontend.min.js';
    }

    /**
     * Cache-bust suffix: module version + served-bundle mtime, so a rebuilt asset invalidates the
     * browser cache automatically.
     */
    public function getMollieModuleVersion(): string
    {
        $bundle = __DIR__ . '/../../../assets/' . $this->getMollieJsPath();
        $mtime = is_file($bundle) ? filemtime($bundle) : false;

        return '1.0.0-' . ($mtime === false ? '0' : (string) $mtime);
    }

    protected function mollieConfigService(): ?ModuleConfigurationServiceInterface
    {
        /** @phpstan-ignore-next-line container.notFound */
        $service = ContainerFactory::getInstance()->getContainer()->get(ModuleConfigurationServiceInterface::class);

        return $service instanceof ModuleConfigurationServiceInterface ? $service : null;
    }

    protected function mollieIframeSettings(): ?IframeCheckoutSettingsInterface
    {
        try {
            /** @phpstan-ignore-next-line container.notFound */
            $service = ContainerFactory::getInstance()->getContainer()->get(IframeCheckoutSettingsInterface::class);

            return $service instanceof IframeCheckoutSettingsInterface ? $service : null;
        } catch (Throwable) {
            return null;
        }
    }
}
