<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\ConfigFixtures;
use PHPUnit\Framework\TestCase;

final class MollieConfigSnapshotTest extends TestCase
{
    public function testVersion_ChangesWithAnySetting(): void
    {
        $base = ConfigFixtures::snapshot()->version();

        foreach (
            [
                ['mode' => 'live'], ['captureMode' => 'manual'], ['multiCapture' => true], ['profileId' => 'pfl_x'],
                ['webhookUrlOverride' => 'https://a.test'], ['logLevel' => 'debug'], ['testKeySet' => false], ['liveKeySet' => true],
            ] as $change
        ) {
            self::assertNotSame($base, ConfigFixtures::snapshot($change)->version(), json_encode($change));
        }
    }

    public function testVersion_IgnoresTheDerivedWebhookUrl(): void
    {
        self::assertSame(
            ConfigFixtures::snapshot()->version(),
            ConfigFixtures::snapshot(['effectiveWebhookUrl' => 'https://other-shop.test/hook'])->version(),
        );
    }

    public function testVersion_IgnoresTheClassicSettingsUrl(): void
    {
        self::assertSame(
            ConfigFixtures::snapshot()->version(),
            ConfigFixtures::snapshot(['classicSettingsUrl' => 'https://other.test/admin/index.php'])->version(),
        );
    }

    public function testKeySet_AnswersPerMode(): void
    {
        $snapshot = ConfigFixtures::snapshot(['testKeySet' => true, 'liveKeySet' => false]);

        self::assertTrue($snapshot->keySet('test'));
        self::assertFalse($snapshot->keySet('live'));
    }
}
