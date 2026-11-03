<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\PaymentBase\Refund\ProviderRefundLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookEventHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;

/**
 * Handles Mollie `refunded` status webhooks.
 *
 * VR-21 F6: each refund of the payment is booked through payment-base's ledger by its Mollie
 * refund id - the admin refund books the same id, so whichever path comes second is a duplicate
 * and changes nothing. Before, this handler booked "Mollie's cumulative total minus the contract's"
 * and raced the admin refund, and it answered `refund_recorded` even when it recorded nothing.
 * A failure listing the refunds propagates: the delivery fails and Mollie retries it.
 */
final class PaymentRefundedHandler implements MollieWebhookEventHandlerInterface
{
    private const STATUS_REFUNDED = 'refunded';
    private const NOT_RUN = ['failed', 'canceled'];

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly MollieRefundAdapterInterface $refunds,
        private readonly ProviderRefundLedgerInterface $ledger,
    ) {
    }

    public function handledStatuses(): array
    {
        return [self::STATUS_REFUNDED];
    }

    public function handle(WebhookEvent $event): MollieWebhookOutcome
    {
        $providerOrderId = $event->getObjectId();
        if ($providerOrderId === null) {
            return MollieWebhookOutcome::of(WebhookResult::failure('invalid_event', 'Missing Mollie payment id'));
        }

        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return MollieWebhookOutcome::of(WebhookResult::skipped('Contract not found'));
        }

        $contractId = (string) $contract->getId();
        $booked = 0;
        foreach ($this->refunds->listRefunds($providerOrderId) as $refund) {
            if (in_array($refund->status, self::NOT_RUN, true)) {
                continue;
            }
            $booking = $this->ledger->book(
                $contractId,
                $refund->id,
                $refund->amount->value,
                $refund->amount->currency,
                'webhook',
                $refund->hasSucceeded()
            );
            $booked += $booking->booked ? 1 : 0;
        }

        if ($booked === 0) {
            return MollieWebhookOutcome::of(WebhookResult::skipped('No new refund to record'), $contractId);
        }

        return MollieWebhookOutcome::of(WebhookResult::success('refund_recorded'), $contractId);
    }
}
