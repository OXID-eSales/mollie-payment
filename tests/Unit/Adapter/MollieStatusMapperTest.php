<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter;

use OxidEsales\Payments\Mollie\Adapter\MollieOutcome;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieStatusMapper::class)]
final class MollieStatusMapperTest extends TestCase
{
    private MollieStatusMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new MollieStatusMapper();
    }

    public function testPaid_MapsToPaid(): void
    {
        self::assertSame(MollieOutcome::PAID, $this->mapper->map('paid'));
    }

    public function testAuthorized_MapsToAuthorized(): void
    {
        self::assertSame(MollieOutcome::AUTHORIZED, $this->mapper->map('authorized'));
    }

    public function testOpenAndPending_MapToPending(): void
    {
        self::assertSame(MollieOutcome::PENDING, $this->mapper->map('open'));
        self::assertSame(MollieOutcome::PENDING, $this->mapper->map('pending'));
    }

    public function testExpired_MapsToExpired(): void
    {
        self::assertSame(MollieOutcome::EXPIRED, $this->mapper->map('expired'));
    }

    public function testCanceledAndFailed_MapToTerminalFailureStates(): void
    {
        self::assertSame(MollieOutcome::CANCELED, $this->mapper->map('canceled'));
        self::assertSame(MollieOutcome::FAILED, $this->mapper->map('failed'));
    }

    public function testUnknownStatus_MapsToIgnored(): void
    {
        self::assertSame(MollieOutcome::IGNORED, $this->mapper->map('some_future_status'));
        self::assertSame(MollieOutcome::IGNORED, $this->mapper->map(''));
    }
}
