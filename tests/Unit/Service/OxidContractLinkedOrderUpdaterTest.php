<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Adapter\LinkedOrderEndingInterface;
use OxidEsales\Payments\Mollie\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Mollie\Service\OxidContractLinkedOrderUpdater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S4: the order ends the shared payment-base way. Real storno, stock
 * and vouchers: Integration LinkedOrderEndReleasesVoucherTest.
 */
#[CoversClass(OxidContractLinkedOrderUpdater::class)]
final class OxidContractLinkedOrderUpdaterTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        self::assertInstanceOf(
            ContractLinkedOrderUpdaterInterface::class,
            new OxidContractLinkedOrderUpdater($this->createMock(LinkedOrderEndingInterface::class))
        );
    }

    public function testMarkCancelled_EndsTheOrderAsCancelled(): void
    {
        $ending = $this->createMock(LinkedOrderEndingInterface::class);
        $ending->expects(self::once())->method('end')->with('order-123', 'CANCELLED');

        (new OxidContractLinkedOrderUpdater($ending))->markCancelled('order-123');
    }

    public function testMarkFailed_EndsTheOrderAsFailed(): void
    {
        $ending = $this->createMock(LinkedOrderEndingInterface::class);
        $ending->expects(self::once())->method('end')->with('order-456', 'FAILED');

        (new OxidContractLinkedOrderUpdater($ending))->markFailed('order-456', 'payment_declined');
    }
}
