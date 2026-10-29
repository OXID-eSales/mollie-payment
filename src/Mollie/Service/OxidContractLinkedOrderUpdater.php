<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Adapter\LinkedOrderEndingInterface;

/**
 * OXID-side {@see ContractLinkedOrderUpdaterInterface} implementation.
 *
 * OPC-175 / S4 (G6): the order ends the shared way (payment-base
 * {@see LinkedOrderEndingInterface}): an unpaid order is stornoed, its stock and
 * vouchers come back. Before, only OXTRANSSTATUS was set - the coupon stayed
 * used for good and the cleanup command (NOT_FINISHED only) never saw the order.
 */
class OxidContractLinkedOrderUpdater implements ContractLinkedOrderUpdaterInterface
{
    public function __construct(
        private readonly LinkedOrderEndingInterface $ending
    ) {
    }

    public function markCancelled(string $orderId): void
    {
        $this->ending->end($orderId, LinkedOrderEndingInterface::STATUS_CANCELLED);
    }

    public function markFailed(string $orderId, string $reason): void
    {
        $this->ending->end($orderId, LinkedOrderEndingInterface::STATUS_FAILED);
    }
}
