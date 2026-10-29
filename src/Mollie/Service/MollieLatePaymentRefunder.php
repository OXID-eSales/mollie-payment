<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use LogicException;
use OxidEsales\PaymentBase\Contract\ReplacedAttempts;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\ReplacedAttemptFinderInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * OPC-175 / S2g — money on a Mollie payment the customer left for another
 * payment method.
 *
 * Mollie cannot cancel every open payment, so the customer can still pay it
 * after the contract continued with a new attempt. That money belongs to no
 * order: a paid payment is refunded in full, an authorization is released.
 * The outcome is recorded on the replaced attempt in the contract's history,
 * once - Mollie calls the webhook more than once. Logged as an error: the
 * merchant must see money that came and went back.
 *
 * Not final: the webhook handler tests replace it.
 *
 * @since OPC-175
 */
class MollieLatePaymentRefunder
{
    private const PAID = 'paid';
    private const AUTHORIZED = 'authorized';

    public function __construct(
        private readonly ReplacedAttemptFinderInterface $finder,
        private readonly MolliePaymentsAdapterInterface $payments,
        private readonly MollieRefundAdapterInterface $refunds,
        private readonly ContractRepositoryInterface $contracts,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return string|null the contract id when the payment is of a replaced attempt, null otherwise
     */
    public function settleIfReplaced(string $paymentId): ?string
    {
        $contract = $this->finder->findByReplacedAttempt($paymentId);
        if ($contract === null) {
            return null;
        }

        $history = $contract->getMetadata(ReplacedAttempts::METADATA_KEY);
        $attempt = ReplacedAttempts::indexOf($history, $paymentId);
        if ($attempt === null) {
            throw new LogicException(sprintf('Contract %s has no replaced attempt %s', $contract->getId(), $paymentId));
        }
        if (ReplacedAttempts::lateMoneyIn($history, $attempt) !== null) {
            return $contract->getId();
        }

        $outcome = $this->giveBack($paymentId);
        if ($outcome === null) {
            // Not money: nothing is recorded, so a later paid/authorized call is handled.
            return $contract->getId();
        }

        $contract->setMetadata(
            ReplacedAttempts::METADATA_KEY,
            ReplacedAttempts::withLateMoney($history, $attempt, $outcome)
        );
        $this->contracts->save($contract);

        $this->logger->error('Money on a replaced Mollie checkout attempt', [
            'contract_id' => $contract->getId(),
            'order_id' => $contract->getOrderId(),
            'attempt' => $attempt,
            'payment_id' => $paymentId,
            'outcome' => $outcome,
        ]);

        return $contract->getId();
    }

    private function giveBack(string $paymentId): ?string
    {
        $payment = $this->payments->getPayment($paymentId);

        if ($payment->status === self::AUTHORIZED) {
            $this->payments->releaseAuthorization($paymentId);

            return 'released';
        }

        if ($payment->status === self::PAID) {
            $refund = $this->refunds->createRefund(new RefundRequest(
                paymentId: $paymentId,
                amount: $payment->amount,
                idempotencyKey: 'opc175-' . $paymentId,
                description: 'Payment of a replaced checkout attempt',
            ));

            return 'refunded:' . $refund->id;
        }

        return null;
    }
}
