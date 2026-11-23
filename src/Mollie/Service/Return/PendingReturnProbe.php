<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service\Return;

use Psr\Log\LoggerInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieOutcome;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use Throwable;

/**
 * Classifies a returned contract's live Mollie status as pending or not.
 *
 * On the checkout return leg a null order id covers two cases the responder can't distinguish:
 * a hard failure, and a still-open/pending payment left for the webhook (common for PayPal /
 * bank-style methods). This probe queries Mollie once to tell them apart. Fail-closed: any
 * doubt (no payment id, adapter unavailable, API error) reads as "not pending" so the caller
 * falls through to its error path — never to a false thank-you.
 *
 * Extracted from MollieOrderController::returnIsPending(): an API concern,
 * not controller flow — and the controller sat at the PHPMD class-complexity threshold.
 */
final class PendingReturnProbe
{
    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly MollieStatusMapper $statusMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isPending(PaymentContractInterface $contract): bool
    {
        $paymentId = $contract->getProviderOrderId();
        if ($paymentId === null || $paymentId === '') {
            return false;
        }

        try {
            return $this->statusMapper->map($this->paymentsAdapter->getPayment($paymentId)->status)
                === MollieOutcome::PENDING;
        } catch (Throwable $failure) {
            // "Not pending" is the answer the return page can act on. The cause must be in a log: the
            // shopper sees the error page and nothing said why (sprint 149 AW). Class only: the text of
            // a provider exception can carry the request.
            $this->logger->warning('[PendingReturnProbe] Mollie did not answer for the returning payment', [
                'contractId' => $contract->getId(),
                'exception' => $failure::class,
            ]);

            return false;
        }
    }
}
