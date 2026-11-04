<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Reconciliation\ProviderPaymentStatus;
use OxidEsales\PaymentBase\Reconciliation\ProviderPaymentStatusInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Mollie's answer to payment-base's `oe:payments:reconcile-oxpaid`: the live Mollie payment is the
 * truth, and OXPAID gets Mollie's own `paidAt`. A paid payment without that time is reported as
 * such, so the shared service leaves the order for manual review instead of stamping "now".
 */
final class MolliePaidStatusProvider implements ProviderPaymentStatusInterface
{
    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
    ) {
    }

    public function provider(): string
    {
        return MollieDefinitions::PROVIDER_NAME;
    }

    public function statusOf(string $providerOrderId): ProviderPaymentStatus
    {
        $payment = $this->paymentsAdapter->getPayment($providerOrderId);
        if ($payment->status !== MollieStatusMapper::STATUS_PAID) {
            return ProviderPaymentStatus::notPaid($payment->status);
        }
        if ($payment->paidAt === null || $payment->paidAt === '') {
            return ProviderPaymentStatus::paidWithoutTimestamp($payment->status);
        }

        return ProviderPaymentStatus::paidAt($payment->status, new DateTimeImmutable($payment->paidAt));
    }
}
