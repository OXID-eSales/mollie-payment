<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigChange;
use PHPUnit\Framework\TestCase;

final class MollieConfigChangeTest extends TestCase
{
    public function testFieldNames_ListsOnlyWhatTheCallCarries_NeverAValue(): void
    {
        $change = new MollieConfigChange('v', captureMode: 'manual', multiCapture: false, testKey: 'test_abc', liveKey: '');

        self::assertSame(['captureMode', 'multiCapture', 'testKey'], $change->fieldNames());
    }

    public function testKeyFor_AnEmptyOrMissingKeyMeansUnchanged(): void
    {
        $change = new MollieConfigChange('v', testKey: '', liveKey: null);

        self::assertNull($change->keyFor('test'));
        self::assertNull($change->keyFor('live'));
        self::assertFalse($change->writesKey());
    }

    public function testWritesKey_IsTrueForAnyNewKey(): void
    {
        self::assertTrue((new MollieConfigChange('v', liveKey: 'live_x'))->writesKey());
    }

    public function testTheKeysAreSensitiveParameters(): void
    {
        $constructor = (new \ReflectionClass(MollieConfigChange::class))->getConstructor();
        $sensitive = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                $sensitive[] = $parameter->getName();
            }
        }

        self::assertSame(['testKey', 'liveKey'], $sensitive);
    }
}
