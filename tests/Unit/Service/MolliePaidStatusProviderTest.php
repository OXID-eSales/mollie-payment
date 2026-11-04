<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use DateTimeImmutable;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Service\MolliePaidStatusProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MolliePaidStatusProvider::class)]
final class MolliePaidStatusProviderTest extends TestCase
{
    public function testProviderIsMollie(): void
    {
        $provider = new MolliePaidStatusProvider($this->createMock(MolliePaymentsAdapterInterface::class));

        self::assertSame('mollie', $provider->provider());
    }

    public function testPaidPaymentCarriesMollieTime(): void
    {
        $status = $this->providerFor($this->payment('paid', '2026-07-08T10:20:00+00:00'))->statusOf('tr_1');

        self::assertTrue($status->paid);
        self::assertSame('paid', $status->status);
        self::assertEquals(new DateTimeImmutable('2026-07-08T10:20:00+00:00'), $status->paidAt);
    }

    public function testPaidPaymentWithoutTimeIsReportedWithoutInventingOne(): void
    {
        $status = $this->providerFor($this->payment('paid', null))->statusOf('tr_1');

        self::assertTrue($status->paid);
        self::assertNull($status->paidAt);
    }

    public function testOpenPaymentIsNotPaid(): void
    {
        $status = $this->providerFor($this->payment('open', null))->statusOf('tr_1');

        self::assertFalse($status->paid);
        self::assertSame('open', $status->status);
        self::assertNull($status->paidAt);
    }

    private function payment(string $status, ?string $paidAt): MolliePaymentDto
    {
        return new MolliePaymentDto('tr_1', $status, MollieAmountDto::fromComponents('EUR', 50.0), paidAt: $paidAt);
    }

    private function providerFor(MolliePaymentDto $payment): MolliePaidStatusProvider
    {
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->method('getPayment')->with('tr_1')->willReturn($payment);

        return new MolliePaidStatusProvider($adapter);
    }
}
