<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Single source of truth for Mollie module configuration (mode, credentials, capture mode,
 * logging). Concrete implementation lands in the shop-glue/config sprint; the adapter factory
 * and shop adapter depend on this interface (DIP), never on Registry::getConfig() reach-ins.
 */
interface ModuleConfigurationServiceInterface
{
    public function getMode(): string;

    public function isTestMode(): bool;

    /**
     * The API key for the active mode (test_… in test mode, live_… in live mode).
     */
    public function getApiKey(): string;

    public function getCaptureMode(): string;

    public function isManualCapture(): bool;

    public function getWebhookUrl(): string;

    public function getLogLevel(): string;

    /**
     * True for any level except "off". Gates the webhook file-logger channel
     * (see {@see \OxidEsales\Payments\Mollie\Service\Factory\MollieWebhookFileLoggerFactory}).
     */
    public function isWebhookLoggingEnabled(): bool;

    /**
     * True only at the "debug" level. Gates the frontend console debug wrapper
     * (mirrors Stripe's runtime-flag-driven frontend logging).
     */
    public function isFrontendDebugEnabled(): bool;
}
