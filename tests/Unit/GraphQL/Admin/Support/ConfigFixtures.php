<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigSnapshot;

final class ConfigFixtures
{
    public const TEST_KEY = 'test_AbCdEfGhIjKlMnOpQrStUvWxYz0123';
    public const LIVE_KEY = 'live_AbCdEfGhIjKlMnOpQrStUvWxYz4567';

    /**
     * @param array<string, mixed> $overrides constructor arguments of the snapshot, by name
     */
    public static function snapshot(array $overrides = []): MollieConfigSnapshot
    {
        return new MollieConfigSnapshot(...array_merge([
            'mode' => 'test',
            'captureMode' => 'automatic',
            'multiCapture' => false,
            'profileId' => 'pfl_AbC123',
            'webhookUrlOverride' => '',
            'effectiveWebhookUrl' => 'https://shop.test/index.php?cl=MollieWebhookController',
            'logLevel' => 'errors',
            'testKeySet' => true,
            'liveKeySet' => false,
        ], $overrides));
    }
}
