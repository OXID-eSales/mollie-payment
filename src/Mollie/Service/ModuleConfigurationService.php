<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Settings\AbstractModuleConfigurationService;
use OxidEsales\PaymentBase\Settings\ModuleConfigurationUnreadableException;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Module;

/**
 * Typed access to Mollie module configuration, on payment-base's {@see AbstractModuleConfigurationService}.
 *
 * Reads OXID module settings and exposes a mode-aware API: the active API key (test_… vs live_…) is
 * selected automatically from the configured mode. This is the single source of truth for
 * credentials/mode.
 *
 * Sprint 11 Story 7 (F4) drew the line this class had been blurring. Two situations look the same to
 * a naive reader and must not be treated the same:
 *
 * - a setting is **unset** — a real default applies (metadata.php declares `test` for the mode);
 * - the configuration is **unreadable** — the DAO threw, the shop id is wrong, the row is gone. The
 *   module has no idea which Mollie account it is pointed at.
 *
 * The second case used to resolve to `''`, which is not `'live'`, which meant **test**, which
 * selected `sMollieTestKey`. A live shop with a test key on file therefore kept "taking" payments
 * against Mollie's test account while fulfilling real orders. Mode and key now fail closed together:
 * {@see getMode()}, {@see isTestMode()} and {@see getApiKey()} raise
 * {@see ModuleConfigurationUnreadableException} rather than guess. Everything else still degrades to a
 * default, but no longer in silence.
 */
class ModuleConfigurationService extends AbstractModuleConfigurationService implements ModuleConfigurationServiceInterface
{
    protected function moduleId(): string
    {
        return Module::MODULE_ID;
    }

    /**
     * @throws ModuleConfigurationUnreadableException when the configuration could not be read at all
     */
    public function getMode(): string
    {
        $this->assertReadable();

        return $this->get('sMollieMode') === MollieDefinitions::MODE_LIVE
            ? MollieDefinitions::MODE_LIVE
            : MollieDefinitions::MODE_TEST;
    }

    /**
     * @throws ModuleConfigurationUnreadableException when the configuration could not be read at all
     */
    public function isTestMode(): bool
    {
        return $this->getMode() === MollieDefinitions::MODE_TEST;
    }

    /**
     * @throws ModuleConfigurationUnreadableException when the configuration could not be read at all
     */
    public function getApiKey(): string
    {
        return trim($this->readString($this->isTestMode() ? 'sMollieTestKey' : 'sMollieLiveKey'));
    }

    public function getProfileId(): string
    {
        $value = $this->get('sMollieProfileId');
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

    public function isMultiCaptureEnabled(): bool
    {
        // OXID stores a bool setting as true / '1' (on) or false / '' (off); the cast covers both.
        return (bool) $this->get('blMollieMultiCapture');
    }

    /**
     * The merchant's explicit webhook-URL override, or '' when they have not set one.
     *
     * Sprint 11 Story 7 (F20): this method used to *derive* the URL, reaching into
     * `Registry::getConfig()->getShopUrl()` — a reach-in this module's own rules forbid inside a
     * service, and one this class's docblock simultaneously claimed did not exist. Deriving the URL
     * is a different responsibility from reading configuration, so it moved to
     * {@see MollieWebhookUrlProvider}.
     */
    public function getWebhookUrlOverride(): string
    {
        $configured = $this->get('sMollieWebhookUrl');

        return is_string($configured) ? trim($configured) : '';
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
