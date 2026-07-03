<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Writes an audit row to `oe_payments_transaction` for a webhook-driven contract event.
 *
 * Extracted as its own service (rather than inlined per handler) so both the webhook
 * fulfillment ladder (capture/failure/expiration/cancellation) and the chargeback handler
 * share exactly one place that knows how to build a {@see Transaction}. Refunds are
 * deliberately NOT recorded here — {@see ContractRefundRecorder} owns the contract-level
 * refundedAmount bookkeeping and payment-base's Transaction model has no "refund" row
 * requirement for Mollie (mirrors Stripe's ContractRefundRecorder, which also stays out of
 * TransactionRepository).
 */
final class TransactionAuditRecorder
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly ShopAdapterInterface $shopAdapter,
    ) {
    }

    public function record(PaymentContractInterface $contract, string $type, string $status, float $amount): void
    {
        $transaction = new Transaction(
            id: $type . '_' . bin2hex(random_bytes(16)),
            shopId: (int) $this->shopAdapter->getShopId(),
            orderId: $contract->getOrderId() ?? '',
            contractId: $contract->getId(),
            provider: MollieDefinitions::PROVIDER_NAME,
            type: $type,
            status: $status,
            amount: $amount,
            currency: $contract->getCurrency(),
        );
        $transaction->setProviderOrderId($contract->getProviderOrderId());

        $this->transactionRepository->save($transaction);
    }
}
