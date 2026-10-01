<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Service\AuthorizationReleaseMarker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizationReleaseMarker::class)]
final class AuthorizationReleaseMarkerTest extends TestCase
{
    public function testIsReleased_WithoutMarker_False(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getMetadata')->with(AuthorizationReleaseMarker::METADATA_KEY)->willReturn(null);

        self::assertFalse(AuthorizationReleaseMarker::isReleased($contract));
    }

    public function testMark_WritesAmountReasonAndTimestamp_ThenReadsAsReleased(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->expects(self::once())->method('setMetadata')->with(
            AuthorizationReleaseMarker::METADATA_KEY,
            ['amount' => 59.99, 'reason' => 'duplicate', 'releasedAt' => '2026-10-01T12:00:00+00:00'],
        );
        $contract->method('getMetadata')->willReturn(['amount' => 59.99]);

        AuthorizationReleaseMarker::mark(
            $contract,
            59.994,
            'duplicate',
            new DateTimeImmutable('2026-10-01T12:00:00+00:00'),
        );

        self::assertTrue(AuthorizationReleaseMarker::isReleased($contract));
    }
}
