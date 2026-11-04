<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\LateMoney\LateMoneyResolverInterface;
use OxidEsales\PaymentBase\Repository\ReplacedAttemptFinderInterface;

/**
 * Money Mollie reports for a contract that can no longer take it: the contract was closed without payment
 * (cancelled by the stale-order cleanup or an admin, expired, failed), or the payment belongs to an attempt the
 * contract replaced. Shared by the `paid` and `authorized` paths; both answer the webhook the same way.
 */
final class ClosedContractLateMoney
{
    public function __construct(
        private readonly LateMoneyResolverInterface $lateMoney,
        private readonly ReplacedAttemptFinderInterface $replacedAttempts,
    ) {
    }

    /**
     * OPC-175 / S2g: a payment no contract names may be one of a replaced attempt.
     */
    public function forUnknownPayment(string $providerOrderId): FulfillmentResult
    {
        $contract = $this->replacedAttempts->findByReplacedAttempt($providerOrderId);
        if ($contract === null) {
            return FulfillmentResult::contractNotFound();
        }

        return $this->giveBack($contract, $providerOrderId);
    }

    /**
     * VR-23 T2 (S-H3): the resolver refunds / releases once and records it; a refusal by Mollie is flagged by the
     * resolver, not retried: Mollie is answered 200 either way, instead of a 5xx until it gives up with the money
     * taken and no order.
     */
    public function giveBack(PaymentContractInterface $contract, string $providerOrderId): FulfillmentResult
    {
        $this->lateMoney->resolve($contract, $providerOrderId);

        return FulfillmentResult::noOp((string) $contract->getId());
    }

    public function isClosedWithoutPayment(PaymentContractInterface $contract): bool
    {
        return $contract->getState()->isTerminal() && !$contract->getState()->isFulfilled();
    }
}
