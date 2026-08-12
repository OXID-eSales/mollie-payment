<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieConfigurationException;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Module;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Typed, cached access to Mollie module configuration.
 *
 * Reads OXID module settings through ModuleConfigurationDaoInterface and exposes a mode-aware API:
 * the active API key (test_… vs live_…) is selected automatically from the configured mode. This
 * is the single source of truth for credentials/mode. Mirrors PayPal's ModuleConfigurationService.
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
 * {@see MollieConfigurationException} rather than guess. Everything else still degrades to a default,
 * but no longer in silence.
 */
class ModuleConfigurationService implements ModuleConfigurationServiceInterface
{
    private ?ModuleConfiguration $moduleConfig = null;
    private bool $configurationUnreadable = false;
    private LoggerInterface $logger;

    public function __construct(
        ContextInterface $context,
        ModuleConfigurationDaoInterface $moduleConfigurationDao,
        LoggerInterface $logger,
    ) {
        $this->logger = $logger;

        try {
            $this->moduleConfig = $moduleConfigurationDao->get(
                Module::MODULE_ID,
                $context->getCurrentShopId(),
            );
        } catch (Throwable $e) {
            $this->moduleConfig = null;
            $this->configurationUnreadable = true;
            $this->logger->error(
                '[ModuleConfigurationService] Mollie module configuration could not be read; '
                . 'mode and API key will fail closed',
                ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * Constructor seam for test doubles that must model an unreadable configuration without a DAO.
     */
    protected function initializeForTest(bool $unreadable, LoggerInterface $logger): void
    {
        $this->configurationUnreadable = $unreadable;
        $this->logger = $logger;
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
        } catch (Throwable $e) {
            // An absent setting is legitimate (metadata defaults apply), but it must not be
            // indistinguishable from a broken read ever again — hence the log.
            $this->logger->warning('[ModuleConfigurationService] setting unreadable, substituting empty', [
                'setting' => $name,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * @throws MollieConfigurationException when the configuration could not be read at all
     */
    public function getMode(): string
    {
        $this->assertConfigurationIsReadable();

        return $this->get('sMollieMode') === MollieDefinitions::MODE_LIVE
            ? MollieDefinitions::MODE_LIVE
            : MollieDefinitions::MODE_TEST;
    }

    /**
     * @throws MollieConfigurationException when the configuration could not be read at all
     */
    public function isTestMode(): bool
    {
        return $this->getMode() === MollieDefinitions::MODE_TEST;
    }

    /**
     * @throws MollieConfigurationException when the configuration could not be read at all
     */
    public function getApiKey(): string
    {
        $value = $this->get($this->isTestMode() ? 'sMollieTestKey' : 'sMollieLiveKey');
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Mode and key are the pair that decides WHICH MOLLIE ACCOUNT gets charged, so they are the pair
     * that must never be guessed.
     *
     * @throws MollieConfigurationException
     */
    private function assertConfigurationIsReadable(): void
    {
        if (!$this->configurationUnreadable) {
            return;
        }

        $this->logger->error(
            '[ModuleConfigurationService] refusing to resolve Mollie mode/API key from an unreadable '
            . 'configuration',
            ['moduleId' => Module::MODULE_ID],
        );

        throw new MollieConfigurationException(
            'Mollie module configuration is unreadable: the active mode and API key cannot be '
            . 'determined, so no payment may be attempted.',
        );
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

    /**
     * The merchant's explicit webhook-URL override, or '' when they have not set one.
     *
     * Sprint 11 Story 7 (F20): this method used to *derive* the URL, reaching into
     * `Registry::getConfig()->getShopUrl()` — a reach-in this module's own rules forbid inside a
     * service, and one this class's docblock simultaneously claimed did not exist. Deriving the URL
     * is a different responsibility from reading configuration, so it moved to
     * {@see MollieWebhookUrlProvider}, which can hold a ShopAdapterInterface without creating a
     * dependency cycle back into this service.
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
