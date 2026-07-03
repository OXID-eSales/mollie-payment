<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\EventSystem\Translator;

use OxidEsales\PaymentBase\EventSystem\Broker\ProviderEventTranslatorInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CancelAuthorizationRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CaptureRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCancelAuthorizationRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCaptureRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieRefundRequestEvent;

/**
 * Maps payment-base's provider-agnostic admin request events onto Mollie's concrete event
 * classes, and stamps a deterministic idempotency key on each: `{contractId}:{action}[:amount]`.
 * That key travels through to the Mollie SDK's idempotency-key header (via
 * {@see \OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest}/{@see \OxidEsales\Payments\Mollie\Adapter\Dto\CaptureRequest}),
 * so a duplicate admin click (same contract, same action, same amount) short-circuits at the
 * Mollie API rather than creating a second refund/capture.
 *
 * Mirrors PayPal's `PayPalEventTranslator`: reads the contract off the abstract event's
 * `EventContext` rather than requiring one to be threaded separately.
 */
final class MollieEventTranslator implements ProviderEventTranslatorInterface
{
    private const ACTION_REFUND = 'refund';
    private const ACTION_CAPTURE = 'capture';
    private const ACTION_CANCEL_AUTHORIZATION = 'cancel_authorization';

    public function supports(string $providerName): bool
    {
        return $providerName === MollieDefinitions::PROVIDER_NAME;
    }

    public function translate(AbstractProviderRequestEvent $event): ?EventInterface
    {
        $contract = $event->getContext()->getContract();
        if ($contract === null) {
            return null;
        }

        if ($event instanceof RefundRequestedEvent) {
            return new MollieRefundRequestEvent(
                $contract,
                $event->getAmount(),
                $event->getReason(),
                $this->buildIdempotencyKey($contract->getId(), self::ACTION_REFUND, $event->getAmount()),
            );
        }

        if ($event instanceof CaptureRequestedEvent) {
            return new MollieCaptureRequestEvent(
                $contract,
                $event->getAmount(),
                $event->getReason(),
                $this->buildIdempotencyKey($contract->getId(), self::ACTION_CAPTURE, $event->getAmount()),
            );
        }

        if ($event instanceof CancelAuthorizationRequestedEvent) {
            return new MollieCancelAuthorizationRequestEvent(
                $contract,
                $event->getReason(),
                $this->buildIdempotencyKey($contract->getId(), self::ACTION_CANCEL_AUTHORIZATION, null),
            );
        }

        return null;
    }

    private function buildIdempotencyKey(?string $contractId, string $action, ?float $amount): string
    {
        $key = ($contractId ?? 'unknown') . ':' . $action;
        if ($amount !== null) {
            $key .= ':' . number_format($amount, 2, '.', '');
        }

        return $key;
    }
}
