<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Service\Factory\AbstractFileLoggerFactory;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;

/**
 * Level-gated file-audit-trail logger for the Mollie webhook endpoint.
 *
 * Mirrors Stripe's harmonised logging design (2026-06-24): the gate is a plain closure over
 * {@see ModuleConfigurationServiceInterface::isWebhookLoggingEnabled()} (true for any level
 * except "off"), evaluated lazily by the parent's create(). Writes to
 * log/mollie/mollie_webhooks_<date>.log — separate from the shop's general PSR-3 log so a
 * merchant can isolate Mollie webhook traffic without raising the shop-wide log level.
 */
class MollieWebhookFileLoggerFactory extends AbstractFileLoggerFactory
{
    public function __construct(ModuleConfigurationServiceInterface $config)
    {
        parent::__construct(static fn (): bool => $config->isWebhookLoggingEnabled());
    }

    protected function getLogFile(): string
    {
        $date = date('Y-m-d');

        return "log/mollie/mollie_webhooks_{$date}.log";
    }

    protected function getPrefix(): string
    {
        return 'WEBHOOK';
    }

    protected function getShopDirectory(): string
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');

        return is_string($shopDir) ? $shopDir : '';
    }
}
