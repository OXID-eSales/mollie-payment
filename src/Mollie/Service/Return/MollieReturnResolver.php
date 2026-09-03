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
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use Psr\Log\LoggerInterface;

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
    /** Methods Mollie is known to settle immediately despite captureMode: manual. */
    private const METHODS_WITHOUT_MANUAL_CAPTURE = ['creditcard', 'cartesbancaires'];

    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly MollieStatusMapper $statusMapper,
        // Optional so an unwired consumer keeps working; both only feed the
        // manual-capture diagnostic below.
        private readonly ?ModuleConfigurationServiceInterface $config = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Mollie can accept `captureMode: manual` and capture anyway.
     *
     * Measured against the live API: a Klarna payment sent with captureMode
     * manual comes back `authorized` (a hold the merchant captures later), but a
     * CARD payment sent with the very same flag comes back `paid`, with no
     * authorizedAt - the flag is stored on the payment and ignored. Manual
     * captures for cards have to be enabled for the card method on the Mollie
     * profile; until they are, the shop asks and Mollie declines, silently.
     *
     * The merchant then finds no capture button and nothing anywhere says why,
     * because the admin panel correctly gates capture on the LIVE status being
     * `authorized`. So say it here, once per return, rather than leave them to
     * guess.
     */
    private function warnIfManualCaptureWasIgnored(MolliePaymentDto $payment): void
    {
        if ($this->config === null || $this->logger === null || !$this->config->isManualCapture()) {
            return;
        }

        if ($payment->status !== MollieStatusMapper::STATUS_PAID) {
            return;
        }

        $method = (string) ($payment->method ?? '');
        if ($method === '' || !in_array(strtolower($method), self::METHODS_WITHOUT_MANUAL_CAPTURE, true)) {
            return;
        }

        $this->logger->warning(
            'Mollie captured a payment the shop asked to authorize only. Manual capture is '
            . 'configured, but Mollie settled this method immediately, so there is no authorization '
            . 'to capture later and the admin will offer no capture button. Manual captures must be '
            . 'enabled for this method on the Mollie profile.',
            ['payment_id' => $payment->id, 'method' => $method, 'status' => $payment->status],
        );
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

        $this->warnIfManualCaptureWasIgnored($payment);

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
            MollieOutcome::CANCELED => ReturnResolution::failed(
                'payment_canceled',
                'Payment was canceled.',
                $paymentId
            ),
            MollieOutcome::EXPIRED => ReturnResolution::failed(
                'payment_expired',
                'Payment window expired.',
                $paymentId
            ),
            MollieOutcome::FAILED => ReturnResolution::failed('payment_failed', 'Payment failed.', $paymentId),
            MollieOutcome::IGNORED => ReturnResolution::failed(
                'payment_status_ignored',
                'Unrecognized Mollie payment status.',
                $paymentId,
            ),
        };
    }

    /**
     * Sprint 11 Story 11 (F17): the contract's currency, verbatim.
     *
     * This used to substitute 'EUR' for a blank value, and that value flows into PaymentAuthorizedEvent
     * → TransactionRecordingHandler, i.e. straight into the recorded transaction row. Guessing there
     * writes a wrong currency into the audit trail; passing the blank through keeps the record honest.
     * (payment-base's ContractService already defaults the contract currency to EUR upstream, so a
     * blank here means something further up is genuinely broken and should look broken.)
     */
    private function currencyOf(PaymentContractInterface $contract): string
    {
        return $contract->getCurrency();
    }
}
