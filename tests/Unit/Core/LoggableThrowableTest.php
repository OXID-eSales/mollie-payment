<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Core;

use OxidEsales\Payments\Mollie\Core\LoggableThrowable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LoggableThrowable::class)]
final class LoggableThrowableTest extends TestCase
{
    public function testSummarisesAThrowableByClassAndCodeNeverByItsMessage(): void
    {
        $summary = LoggableThrowable::summary(new RuntimeException('card tkn_fixture1234 of shopper@example.test', 7));

        self::assertSame(RuntimeException::class, $summary['class']);
        self::assertSame(7, $summary['code']);
        self::assertStringNotContainsString('tkn_fixture', (string) json_encode($summary));
        self::assertStringNotContainsString('shopper@example.test', (string) json_encode($summary));
    }
}
