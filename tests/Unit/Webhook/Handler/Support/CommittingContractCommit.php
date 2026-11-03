<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler\Support;

use DomainException;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;

/**
 * What payment-base's ContractCommitService does to the contract, reduced to the state change the
 * Mollie handler's tests observe: the `payment_authorized` condition is fulfilled and the contract
 * is committed to its order. Records every confirmation it was given.
 */
final class CommittingContractCommit implements ContractCommitServiceInterface
{
    /** @var list<PaymentConfirmation> */
    public array $confirmations = [];

    public function __construct(private readonly ContractRepositoryInterface $contracts)
    {
    }

    public function commit(PaymentConfirmation $confirmation): CommitOutcome
    {
        $this->confirmations[] = $confirmation;
        $contract = $this->contracts->findByProviderOrderId($confirmation->providerOrderId);
        if ($contract === null) {
            return CommitOutcome::refused('contract_not_found');
        }
        try {
            $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
            $contract->commitToOrder((string) $contract->getOrderId());
        } catch (DomainException) {
            return CommitOutcome::refused('contract_' . $contract->getStateValue());
        }

        return CommitOutcome::committed($contract->getOrderId());
    }
}
