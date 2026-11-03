<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\MollieClientConfigSecretPatternProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieClientConfigSecretPatternProvider::class)]
final class MollieClientConfigSecretPatternProviderTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function values(): array
    {
        return [
            'oauth access token' => ['access_abc123', true],
            'live api key' => ['live_' . str_repeat('a', 30), true],
            'test api key' => ['test_' . str_repeat('Z9', 15), true],
            'profile id' => ['pfl_abc123', false],
            'short live word' => ['live_short', false],
            'key with trailing text' => ['live_' . str_repeat('a', 31), false],
        ];
    }

    #[DataProvider('values')]
    public function testRecognisesMollieSecretShapes(string $value, bool $isSecret): void
    {
        $matched = false;
        foreach ((new MollieClientConfigSecretPatternProvider())->secretValuePatterns() as $pattern) {
            $matched = $matched || preg_match($pattern, $value) === 1;
        }

        self::assertSame($isSecret, $matched);
    }
}
