<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;

/**
 * Default {@see OxpaidReconciliationServiceInterface} implementation.
 *
 * Trusts the live Mollie payment (API truth) over whatever OXPAID currently says. Delegates the
 * actual write to {@see OrderPaymentStateServiceInterface::updatePaidTimestamp()}, whose own SQL
 * guard (`WHERE OXPAID = '0000-00-00 00:00:00'`) makes the write itself idempotent — this service
 * only needs to decide whether Mollie considers the payment paid at all.
 */
final class OxpaidReconciliationService implements OxpaidReconciliationServiceInterface
{
    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly OrderPaymentStateServiceInterface $orderPaymentStateService,
    ) {
    }

    public function reconcile(string $orderId, string $providerOrderId): bool
    {
        $payment = $this->paymentsAdapter->getPayment($providerOrderId);
        if ($payment->status !== MollieStatusMapper::STATUS_PAID) {
            return false;
        }

        return $this->orderPaymentStateService->updatePaidTimestamp($orderId);
    }
}
