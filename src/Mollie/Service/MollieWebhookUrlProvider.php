<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use Psr\Log\LoggerInterface;

/**
 * Resolves the `webhookUrl` sent with every Mollie create-payment call: the merchant's explicit
 * override if they set one, otherwise the shop's own webhook controller.
 *
 * Sprint 11 Story 7 (F20). This lived in {@see ModuleConfigurationService::getWebhookUrl()} and
 * reached into `Registry::getConfig()->getShopUrl()` — forbidden inside a service by this module's
 * rules, and contradicted by that class's own docblock. Moving it here is not just tidiness:
 * `ModuleConfigurationService` cannot take a `ShopAdapterInterface` because
 * {@see \OxidEsales\Payments\Mollie\Adapter\OxidShopAdapter} depends on the config service, so
 * injecting it there would close a dependency cycle the container would refuse to compile. Deriving
 * a URL is a separate responsibility from reading configuration, and separating them resolves both
 * problems at once.
 *
 * The URL is also sanity-checked: Mollie will not call an `http://` endpoint, and a webhook Mollie
 * cannot reach produces exactly the symptoms of F1/F2 (orders that never finalize) with none of the
 * evidence — so a non-HTTPS result is logged rather than shipped silently.
 */
final class MollieWebhookUrlProvider implements MollieWebhookUrlProviderInterface
{
    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getWebhookUrl(): string
    {
        $override = $this->config->getWebhookUrlOverride();
        $url = $override !== '' ? $override : $this->deriveFromShopUrl();

        $this->warnIfNotHttps($url, $override !== '');

        return $url;
    }

    private function deriveFromShopUrl(): string
    {
        return rtrim($this->shopAdapter->getShopUrl(), '/')
            . '/index.php?cl=' . MollieDefinitions::WEBHOOK_CONTROLLER_ID;
    }

    private function warnIfNotHttps(string $url, bool $isOverride): void
    {
        if (str_starts_with($url, 'https://')) {
            return;
        }

        $this->logger->warning(
            '[MollieWebhookUrlProvider] webhook URL is not HTTPS; Mollie will not be able to deliver '
            . 'status updates and orders may never finalize',
            ['url' => $url, 'source' => $isOverride ? 'sMollieWebhookUrl' : 'shop url'],
        );
    }
}
