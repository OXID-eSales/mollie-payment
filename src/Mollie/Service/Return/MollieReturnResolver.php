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
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieConfirmedFigures;
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
        // Both feed the manual-capture diagnostic below.
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly LoggerInterface $logger,
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
        if (!$this->config->isManualCapture()) {
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

        return $this->resolutionForOutcome($this->statusMapper->map($payment->status), $payment);
    }

    /**
     * The commit figures are Mollie's own ({@see MollieConfirmedFigures}), never the contract's: payment-base compares
     * them with the contract, which only means something when they come from the other side (VR-26 M1).
     */
    private function resolutionForOutcome(MollieOutcome $outcome, MolliePaymentDto $payment): ReturnResolution
    {
        $paymentId = $payment->id;

        return match ($outcome) {
            MollieOutcome::PAID, MollieOutcome::AUTHORIZED => $this->settledResolution($outcome, $payment),
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

    private function settledResolution(MollieOutcome $outcome, MolliePaymentDto $payment): ReturnResolution
    {
        $figures = MollieConfirmedFigures::fromAmount($payment->amount);
        if ($figures === null) {
            return ReturnResolution::failed(
                'provider_amount_missing',
                'Mollie reported the payment without an amount or currency.',
                $payment->id,
            );
        }

        if ($outcome === MollieOutcome::AUTHORIZED) {
            return ReturnResolution::authorized($payment->id, $payment->id, $figures->amount, $figures->currency);
        }

        return ReturnResolution::readyToCommit($payment->id, $payment->id, $figures->amount, $figures->currency);
    }
}
