<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Field;

/**
 * OXID-side {@see ContractLinkedOrderUpdaterInterface} implementation.
 *
 * Uses `oxNew(Order::class)` for the model lookup so OXID's class extension chain is honoured.
 */
class OxidContractLinkedOrderUpdater implements ContractLinkedOrderUpdaterInterface
{
    private const TRANSSTATUS_CANCELLED = 'CANCELLED';
    private const TRANSSTATUS_FAILED = 'FAILED';

    public function markCancelled(string $orderId): void
    {
        $order = $this->loadOrder($orderId);
        if ($order === null) {
            return;
        }

        $order->oxorder__oxtransstatus = new Field(self::TRANSSTATUS_CANCELLED, Field::T_RAW);
        $order->save();
    }

    public function markFailed(string $orderId, string $reason): void
    {
        $order = $this->loadOrder($orderId);
        if ($order === null) {
            return;
        }

        $order->oxorder__oxtransstatus = new Field(self::TRANSSTATUS_FAILED, Field::T_RAW);
        $order->save();
    }

    /**
     * Load an Order by ID.
     *
     * Protected so a testable subclass can inject a fake Order without requiring a full OXID
     * framework bootstrap (oxNew seam).
     */
    protected function loadOrder(string $orderId): ?Order
    {
        if ($orderId === '') {
            return null;
        }

        /** @var Order $order */
        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            return null;
        }

        return $order;
    }
}
