<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieOutcome;
use OxidEsales\Payments\Mollie\Service\Result\TransactionRow;

/**
 * The "Refunded" figure the admin panel displays, derived from the live refund list.
 *
 * `PaymentContract::getRefundedAmount()` only ever accumulates, so a refund Mollie later cancels
 * (or that fails) stays counted there forever — the panel showed 11.31 for a payment whose only
 * non-voided refunds summed to 1.31 (2026-09-17). The panel already fetches every refund for its
 * transaction table; the displayed total is the sum of exactly those rows, minus the ones Mollie
 * voided, so the number and the table can never disagree.
 *
 * Fallback: {@see \OxidEsales\Payments\Mollie\Service\TransactionHistoryService} returns no rows
 * at all when the payment itself could not be read. "Mollie unreachable" must not render as
 * "nothing refunded", so that case shows the local record instead.
 */
final class RefundedAmountResolver
{
    /**
     * @param list<TransactionRow> $transactions
     */
    public function resolve(array $transactions, PaymentContractInterface $contract): float
    {
        if ($transactions === []) {
            return $contract->getRefundedAmount() ?? 0.0;
        }

        $total = 0.0;
        foreach ($transactions as $row) {
            if ($this->isEffectiveRefund($row)) {
                $total += $row->amount;
            }
        }

        return $total;
    }

    private function isEffectiveRefund(TransactionRow $row): bool
    {
        if ($row->type !== TransactionRow::TYPE_REFUND) {
            return false;
        }

        return !in_array($row->outcome, [MollieOutcome::CANCELED, MollieOutcome::FAILED, MollieOutcome::EXPIRED], true);
    }
}
