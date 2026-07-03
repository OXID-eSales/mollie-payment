<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service\Return;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MollieOutcome;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;

/**
 * Wraps Mollie's getPayment call and maps the outcome onto the provider-neutral
 * {@see ReturnResolution}.
 *
 * Pure data producer — no state transitions, no saves, no dispatches. The shared
 * `CheckoutReturnResponder` (payment-base) owns that work once this resolver returns.
 *
 * Idempotent by construction: re-invoking with the same payment id and an already-settled
 * Mollie status simply reproduces the same resolution; it never assumes finality for anything
 * short of `paid`/`authorized` — an `open`/`pending` payment is left for the webhook.
 */
final class MollieReturnResolver implements ReturnResolverInterface
{
    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly MollieStatusMapper $statusMapper,
    ) {
    }

    public function resolve(
        PaymentContractInterface $contract,
        EventContextInterface $context,
    ): ReturnResolution {
        $paymentId = $contract->getProviderOrderId();
        if ($paymentId === null || $paymentId === '') {
            return ReturnResolution::failed(
                'missing_provider_payment_id',
                'Contract has no Mollie payment id — checkout was never initiated.',
            );
        }

        try {
            $payment = $this->paymentsAdapter->getPayment($paymentId);
        } catch (MollieAdapterException $e) {
            return ReturnResolution::failed('get_payment_failed', $e->getMessage(), $paymentId);
        }

        return $this->resolutionForOutcome(
            $this->statusMapper->map($payment->status),
            $paymentId,
            $contract,
        );
    }

    private function resolutionForOutcome(
        MollieOutcome $outcome,
        string $paymentId,
        PaymentContractInterface $contract,
    ): ReturnResolution {
        $amount = $contract->getAmount();
        $currency = $this->currencyOf($contract);

        return match ($outcome) {
            MollieOutcome::PAID => ReturnResolution::readyToCommit($paymentId, $paymentId, $amount, $currency),
            MollieOutcome::AUTHORIZED => ReturnResolution::authorized($paymentId, $paymentId, $amount, $currency),
            MollieOutcome::PENDING => new ReturnResolution(
                ReturnResolution::OUTCOME_PENDING,
                null,
                $paymentId,
                0.0,
                '',
                requiresCapture: false,
            ),
            MollieOutcome::CANCELED => ReturnResolution::failed('payment_canceled', 'Payment was canceled.', $paymentId),
            MollieOutcome::EXPIRED => ReturnResolution::failed('payment_expired', 'Payment window expired.', $paymentId),
            MollieOutcome::FAILED => ReturnResolution::failed('payment_failed', 'Payment failed.', $paymentId),
            MollieOutcome::IGNORED => ReturnResolution::failed(
                'payment_status_ignored',
                'Unrecognized Mollie payment status.',
                $paymentId,
            ),
        };
    }

    private function currencyOf(PaymentContractInterface $contract): string
    {
        $currency = $contract->getCurrency();

        return $currency !== '' ? $currency : 'EUR';
    }
}
