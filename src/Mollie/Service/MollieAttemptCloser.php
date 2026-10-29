<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Checkout\Attempt\PaymentAttemptCloserInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * OPC-175 — the customer leaves a Mollie attempt for another payment method.
 *
 * Paid or authorized: not replaced. Open or pending: cancelled when Mollie
 * allows it (Closed); otherwise Mollie keeps the page open until it expires
 * (Open — money that still arrives is refunded). Canceled, expired, failed:
 * nothing to do. A Mollie error reaches the caller.
 *
 * @since OPC-175
 */
final class MollieAttemptCloser implements PaymentAttemptCloserInterface
{
    private const PAID = [MollieStatusMapper::STATUS_PAID, MollieStatusMapper::STATUS_AUTHORIZED];
    private const STILL_PAYABLE = [MollieStatusMapper::STATUS_OPEN, MollieStatusMapper::STATUS_PENDING];

    public function __construct(private readonly MolliePaymentsAdapterInterface $payments)
    {
    }

    public function provider(): string
    {
        return MollieDefinitions::PROVIDER_NAME;
    }

    public function close(PaymentContractInterface $contract): AttemptClosure
    {
        $paymentId = (string) $contract->getProviderOrderId();
        if ($paymentId === '') {
            return AttemptClosure::Closed;
        }

        $payment = $this->payments->getPayment($paymentId);
        if (in_array($payment->status, self::PAID, true)) {
            return AttemptClosure::Paid;
        }

        if (!in_array($payment->status, self::STILL_PAYABLE, true)) {
            return AttemptClosure::Closed;
        }

        if (!$payment->isCancelable) {
            return AttemptClosure::Open;
        }

        $this->payments->cancelPayment($paymentId);

        return AttemptClosure::Closed;
    }
}
