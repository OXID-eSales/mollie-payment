<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Records a refund amount on a PaymentContract.
 *
 * Mirrors Stripe's `ContractRefundRecorder` (mollie-payment Sprint 5, Story 5): one place
 * enforces the FULFILLED guard and the delta-only accumulation contract, so both the inbound
 * webhook (`PaymentRefundedHandler`) and Sprint 6's admin-initiated refund reuse it without
 * duplicating the rule.
 *
 * Business rule: `addRefundedAmount()` is only valid on FULFILLED contracts. If the contract
 * is not FULFILLED, the refund already succeeded at the Mollie API level and must not cause an
 * error here — we simply skip the recording (logged as a warning for operator visibility).
 *
 * Delta-only contract: callers MUST pass the incremental amount for this refund event, never
 * the running total reported by the Mollie API. `record()` accumulates via
 * `addRefundedAmount()`; computing the delta from the API's cumulative `amountRefunded` is the
 * caller's responsibility (see `PaymentRefundedHandler`).
 */
final class ContractRefundRecorder
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function record(PaymentContractInterface $contract, float $amount, ?string $contractId = null): void
    {
        if (!$contract->getState()->isFulfilled()) {
            $this->logger->warning('Cannot record refund on contract: not in FULFILLED state', [
                'contractId' => $contractId,
                'state' => $contract->getStateValue(),
            ]);
            return;
        }

        $contract->addRefundedAmount($amount);
        $contract->setRefundedAt(new DateTimeImmutable());
        $this->contractRepository->save($contract);
    }
}
