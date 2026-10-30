<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\ClassicSettingsUrlInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\OxidMollieAdminSettings;
use OxidEsales\Payments\Mollie\Service\MollieWebhookUrlProviderInterface;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\InMemoryModuleSettings;
use PHPUnit\Framework\TestCase;

final class OxidMollieAdminSettingsTest extends TestCase
{
    private const TEST_KEY = 'test_AAAAAAAAAAStoredTestKeyValue0123456789ab';

    private InMemoryModuleSettings $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryModuleSettings([
            'sMollieMode' => 'live',
            'sMollieCaptureMode' => 'manual',
            'blMollieMultiCapture' => true,
            'sMollieProfileId' => ' pfl_abc ',
            'sMollieWebhookUrl' => 'https://hooks.example.com/m',
            'sMollieLogLevel' => 'debug',
            'sMollieTestKey' => self::TEST_KEY,
            'sMollieLiveKey' => '',
        ]);
    }

    public function testSnapshot_ReadsEverySettingAndReportsKeysOnlyAsSet(): void
    {
        $snapshot = $this->settings()->snapshot();

        self::assertSame('live', $snapshot->mode);
        self::assertSame('manual', $snapshot->captureMode);
        self::assertTrue($snapshot->multiCapture);
        self::assertSame('pfl_abc', $snapshot->profileId);
        self::assertSame('https://hooks.example.com/m', $snapshot->webhookUrlOverride);
        self::assertSame('debug', $snapshot->logLevel);
        self::assertTrue($snapshot->testKeySet);
        self::assertFalse($snapshot->liveKeySet);
        self::assertStringNotContainsString(self::TEST_KEY, (string) json_encode($snapshot));
    }

    public function testSnapshot_TheEffectiveWebhookUrlIsAskedForTheOverrideJustRead(): void
    {
        self::assertSame('effective-for:https://hooks.example.com/m', $this->settings()->snapshot()->effectiveWebhookUrl);
    }

    public function testSnapshot_CarriesTheClassicSettingsUrlOfTheModule(): void
    {
        self::assertSame('https://shop.test/admin/index.php?classic', $this->settings()->snapshot()->classicSettingsUrl);
    }

    public function testSnapshot_UnsetOrUnknownValuesFallBackToTheModulesDefaults(): void
    {
        $settings = new OxidMollieAdminSettings(new InMemoryModuleSettings(['sMollieMode' => 'staging', 'sMollieLogLevel' => 'loud']), $this->urls(), $this->classicUrl());

        $snapshot = $settings->snapshot();

        self::assertSame('test', $snapshot->mode);
        self::assertSame('automatic', $snapshot->captureMode);
        self::assertSame('errors', $snapshot->logLevel);
        self::assertFalse($snapshot->multiCapture);
        self::assertFalse($snapshot->testKeySet);
    }

    private function settings(): OxidMollieAdminSettings
    {
        return new OxidMollieAdminSettings($this->store, $this->urls(), $this->classicUrl());
    }

    private function classicUrl(): ClassicSettingsUrlInterface
    {
        return new class implements ClassicSettingsUrlInterface {
            public function url(): string
            {
                return 'https://shop.test/admin/index.php?classic';
            }
        };
    }

    private function urls(): MollieWebhookUrlProviderInterface
    {
        return new class implements MollieWebhookUrlProviderInterface {
            public function getWebhookUrl(): string
            {
                return 'unused';
            }

            public function urlFor(string $override): string
            {
                return 'effective-for:' . $override;
            }
        };
    }
}
