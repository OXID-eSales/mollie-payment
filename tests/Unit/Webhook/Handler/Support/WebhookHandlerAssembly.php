<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler\Support;

use OxidEsales\PaymentBase\AtRisk\PaymentAtRiskFlagInterface;
use OxidEsales\PaymentBase\Contract\ContractTerminationServiceInterface;
use OxidEsales\PaymentBase\LateMoney\LateMoneyResolverInterface;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\ReplacedAttemptFinderInterface;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use OxidEsales\Payments\Mollie\Webhook\Handler\AuthorizedPaymentCommitter;
use OxidEsales\Payments\Mollie\Webhook\Handler\ClosedContractLateMoney;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaidPaymentFulfiller;
use OxidEsales\Payments\Mollie\Webhook\Handler\UnpaidPaymentTerminator;
use OxidEsales\Payments\Mollie\Webhook\Handler\WebhookContractFulfillmentHandler;
use Psr\Log\NullLogger;

/**
 * Wires the real per-status collaborators of {@see WebhookContractFulfillmentHandler} around the given doubles, the
 * way services.yaml wires them.
 */
final class WebhookHandlerAssembly
{
    public static function build(
        ContractRepositoryInterface $contracts,
        ContractFulfillmentServiceInterface $fulfillment,
        ContractTerminationServiceInterface $termination,
        TransactionAuditRecorder $audit,
        LateMoneyResolverInterface $lateMoney,
        PaymentAtRiskFlagInterface $atRisk,
        ReplacedAttemptFinderInterface $replacedAttempts,
        ContractCommitServiceInterface $commit,
        ProviderCaptureLedgerInterface $captures,
    ): WebhookContractFulfillmentHandler {
        $logger = new NullLogger();
        $closedContractMoney = new ClosedContractLateMoney($lateMoney, $replacedAttempts);

        return new WebhookContractFulfillmentHandler(
            new PaidPaymentFulfiller($contracts, $fulfillment, $commit, $captures, $closedContractMoney, $logger),
            new AuthorizedPaymentCommitter($contracts, $commit, $audit, $closedContractMoney, $logger),
            new UnpaidPaymentTerminator($contracts, $termination, $audit, $atRisk),
            $logger,
        );
    }
}
