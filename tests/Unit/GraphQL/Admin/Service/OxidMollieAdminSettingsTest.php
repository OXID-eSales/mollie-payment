<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigChange;
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

    public function testSnapshot_UnsetOrUnknownValuesFallBackToTheModulesDefaults(): void
    {
        $settings = new OxidMollieAdminSettings(new InMemoryModuleSettings(['sMollieMode' => 'staging', 'sMollieLogLevel' => 'loud']), $this->urls());

        $snapshot = $settings->snapshot();

        self::assertSame('test', $snapshot->mode);
        self::assertSame('automatic', $snapshot->captureMode);
        self::assertSame('errors', $snapshot->logLevel);
        self::assertFalse($snapshot->multiCapture);
        self::assertFalse($snapshot->testKeySet);
    }

    public function testApply_WritesOnlyTheFieldsTheChangeCarries(): void
    {
        $this->settings()->apply(new MollieConfigChange(expectedVersion: 'v', captureMode: 'automatic', multiCapture: false, webhookUrlOverride: ''));

        self::assertSame(['sMollieCaptureMode', 'sMollieWebhookUrl', 'blMollieMultiCapture'], $this->store->saved);
        self::assertSame(self::TEST_KEY, $this->store->values['sMollieTestKey']);
        self::assertSame('', $this->store->values['sMollieWebhookUrl'], 'an empty override is a real value: it removes the override');
    }

    public function testApply_AnEmptyKeyIsNotWritten_ANewOneIs(): void
    {
        $this->settings()->apply(new MollieConfigChange(expectedVersion: 'v', testKey: '', liveKey: 'live_AAAAAAAAAANewLiveKey0123456789abcdefghij'));

        self::assertSame(['sMollieLiveKey'], $this->store->saved);
        self::assertSame(self::TEST_KEY, $this->store->values['sMollieTestKey']);
    }

    private function settings(): OxidMollieAdminSettings
    {
        return new OxidMollieAdminSettings($this->store, $this->urls());
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
