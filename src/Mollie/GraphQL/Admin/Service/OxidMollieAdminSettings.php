<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Module;
use OxidEsales\Payments\Mollie\Service\MollieWebhookUrlProviderInterface;

/**
 * The Mollie module settings through OXID's module setting service (the project configuration), the same path as
 * the classic module configuration form. Read fresh on every call (the module's own configuration service caches
 * per request, which would show the old values right after a change). API keys are read only by `key()` and written
 * only when a change carries a new one.
 */
final class OxidMollieAdminSettings implements MollieAdminSettingsInterface
{
    private const STRINGS = [
        'mode' => 'sMollieMode',
        'captureMode' => 'sMollieCaptureMode',
        'profileId' => 'sMollieProfileId',
        'webhookUrlOverride' => 'sMollieWebhookUrl',
        'logLevel' => 'sMollieLogLevel',
    ];
    private const MULTI_CAPTURE = 'blMollieMultiCapture';
    private const KEY_NAMES = [
        MollieDefinitions::MODE_TEST => 'sMollieTestKey',
        MollieDefinitions::MODE_LIVE => 'sMollieLiveKey',
    ];

    public function __construct(
        private readonly ModuleSettingServiceInterface $settings,
        private readonly MollieWebhookUrlProviderInterface $webhookUrls,
    ) {
    }

    public function snapshot(): MollieConfigSnapshot
    {
        $override = trim($this->string(self::STRINGS['webhookUrlOverride']));

        return new MollieConfigSnapshot(
            mode: $this->string(self::STRINGS['mode']) === MollieDefinitions::MODE_LIVE
                ? MollieDefinitions::MODE_LIVE
                : MollieDefinitions::MODE_TEST,
            captureMode: $this->string(self::STRINGS['captureMode']) === MollieDefinitions::CAPTURE_MODE_MANUAL
                ? MollieDefinitions::CAPTURE_MODE_MANUAL
                : MollieDefinitions::CAPTURE_MODE_AUTOMATIC,
            multiCapture: $this->settings->getBoolean(self::MULTI_CAPTURE, Module::MODULE_ID),
            profileId: trim($this->string(self::STRINGS['profileId'])),
            webhookUrlOverride: $override,
            effectiveWebhookUrl: $this->webhookUrls->urlFor($override),
            logLevel: $this->logLevel(),
            testKeySet: $this->key(MollieDefinitions::MODE_TEST) !== '',
            liveKeySet: $this->key(MollieDefinitions::MODE_LIVE) !== '',
        );
    }

    private function key(string $mode): string
    {
        return trim($this->string(self::KEY_NAMES[$mode] ?? self::KEY_NAMES[MollieDefinitions::MODE_TEST]));
    }

    public function apply(MollieConfigChange $change): void
    {
        foreach (self::STRINGS as $field => $setting) {
            $value = $change->$field;
            if ($value !== null) {
                $this->settings->saveString($setting, $value, Module::MODULE_ID);
            }
        }
        if ($change->multiCapture !== null) {
            $this->settings->saveBoolean(self::MULTI_CAPTURE, $change->multiCapture, Module::MODULE_ID);
        }
        foreach (self::KEY_NAMES as $mode => $setting) {
            $key = $change->keyFor($mode);
            if ($key !== null) {
                $this->settings->saveString($setting, $key, Module::MODULE_ID);
            }
        }
    }

    private function logLevel(): string
    {
        $level = $this->string(self::STRINGS['logLevel']);
        $known = [
            MollieDefinitions::LOG_LEVEL_OFF,
            MollieDefinitions::LOG_LEVEL_ERRORS,
            MollieDefinitions::LOG_LEVEL_NORMAL,
            MollieDefinitions::LOG_LEVEL_DEBUG,
        ];

        return in_array($level, $known, true) ? $level : MollieDefinitions::LOG_LEVEL_ERRORS;
    }

    private function string(string $name): string
    {
        return $this->settings->getString($name, Module::MODULE_ID)->toString();
    }
}
