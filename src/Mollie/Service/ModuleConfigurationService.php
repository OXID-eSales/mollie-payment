<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Module;
use Throwable;

/**
 * Typed, cached access to Mollie module configuration.
 *
 * Reads OXID module settings through ModuleConfigurationDaoInterface and exposes a mode-aware API:
 * the active API key (test_… vs live_…) is selected automatically from the configured mode. This
 * is the single source of truth for credentials/mode — no Registry::getConfig() reach-ins live in
 * the rest of the module. Mirrors PayPal's ModuleConfigurationService.
 */
class ModuleConfigurationService implements ModuleConfigurationServiceInterface
{
    private ?ModuleConfiguration $moduleConfig = null;

    public function __construct(
        private readonly ContextInterface $context,
        private readonly ModuleConfigurationDaoInterface $moduleConfigurationDao,
    ) {
        try {
            $this->moduleConfig = $this->moduleConfigurationDao->get(
                Module::MODULE_ID,
                $this->context->getCurrentShopId(),
            );
        } catch (Throwable) {
            $this->moduleConfig = null;
        }
    }

    public function get(string $name): mixed
    {
        return $this->readSetting($name);
    }

    /**
     * Reads a raw setting value. Isolated for testability — a test subclass overrides this without
     * touching the DAO wiring (PayPal/Stripe protected-seam pattern).
     */
    protected function readSetting(string $name): mixed
    {
        if ($this->moduleConfig === null) {
            return '';
        }
        try {
            return $this->moduleConfig->getModuleSetting($name)->getValue();
        } catch (Throwable) {
            return '';
        }
    }

    public function getMode(): string
    {
        return $this->get('sMollieMode') === MollieDefinitions::MODE_LIVE
            ? MollieDefinitions::MODE_LIVE
            : MollieDefinitions::MODE_TEST;
    }

    public function isTestMode(): bool
    {
        return $this->getMode() === MollieDefinitions::MODE_TEST;
    }

    public function getApiKey(): string
    {
        $value = $this->get($this->isTestMode() ? 'sMollieTestKey' : 'sMollieLiveKey');
        return is_string($value) ? trim($value) : '';
    }

    public function getCaptureMode(): string
    {
        return $this->get('sMollieCaptureMode') === MollieDefinitions::CAPTURE_MODE_MANUAL
            ? MollieDefinitions::CAPTURE_MODE_MANUAL
            : MollieDefinitions::CAPTURE_MODE_AUTOMATIC;
    }

    public function isManualCapture(): bool
    {
        return $this->getCaptureMode() === MollieDefinitions::CAPTURE_MODE_MANUAL;
    }

    public function getWebhookUrl(): string
    {
        $configured = $this->get('sMollieWebhookUrl');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $shopUrl = (string) Registry::getConfig()->getShopUrl();
        return rtrim($shopUrl, '/') . '/index.php?cl=MollieWebhookController';
    }

    public function getLogLevel(): string
    {
        $value = $this->get('sMollieLogLevel');
        $allowed = [
            MollieDefinitions::LOG_LEVEL_OFF,
            MollieDefinitions::LOG_LEVEL_ERRORS,
            MollieDefinitions::LOG_LEVEL_NORMAL,
            MollieDefinitions::LOG_LEVEL_DEBUG,
        ];
        return is_string($value) && in_array($value, $allowed, true)
            ? $value
            : MollieDefinitions::LOG_LEVEL_ERRORS;
    }

    public function isWebhookLoggingEnabled(): bool
    {
        return $this->getLogLevel() !== MollieDefinitions::LOG_LEVEL_OFF;
    }

    public function isFrontendDebugEnabled(): bool
    {
        return $this->getLogLevel() === MollieDefinitions::LOG_LEVEL_DEBUG;
    }
}
