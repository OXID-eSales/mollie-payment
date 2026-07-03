<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Self-heals OXPAID when the Mollie API reports a payment as paid but the linked oxorder's
 * OXPAID is still the zero-date (missed or delayed webhook). No payment-base interface exists
 * for this (Stripe's equivalent is its own module-local interface too); kept intentionally
 * narrow (ISP: 1 method) — the broader "sweep all unpaid orders" surface Stripe exposes
 * (`findUnpaidOrders`/`reconcileAll`) is not required by Sprint 6 and is deferred until an admin
 * or console entrypoint actually needs it.
 */
interface OxpaidReconciliationServiceInterface
{
    /**
     * @param string $orderId OXID of the linked oxorder
     * @param string $providerOrderId Mollie payment id (`tr_...`)
     * @return bool True if OXPAID was healed, false if nothing needed fixing
     */
    public function reconcile(string $orderId, string $providerOrderId): bool;
}
