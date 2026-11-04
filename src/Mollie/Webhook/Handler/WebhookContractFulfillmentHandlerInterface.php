<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieConfirmedFigures;

/**
 * Bridges verified Mollie webhook status changes to the contract state machine.
 *
 * Each method looks up the contract by providerOrderId (the Mollie payment id), advances it via
 * named transitions only (never setState()), records an audit transaction, and mirrors terminal
 * transitions onto the linked oxorder.
 *
 * Every method reports what it did — and to which contract — as a {@see FulfillmentResult}
 * (review 2026-10-14, A-M2). The previous
 * tri-state `?bool` was replaced because `false` conflated "already done, nothing to do" with "tried and
 * failed" — and the caller answered Mollie `HTTP 200` for both, so a failed fulfilment was never
 * retried. See {@see FulfillmentOutcome} for the mapping onto HTTP status codes.
 */
interface WebhookContractFulfillmentHandlerInterface
{
    /**
     * Handle a Mollie `paid` status: climbs the contract ladder (PENDING → READY_TO_COMMIT →
     * COMMITTED) as far as it currently allows, then fulfills it. The commit reports Mollie's own figures.
     */
    public function handlePaymentPaid(string $providerOrderId, MollieConfirmedFigures $figures): FulfillmentResult;

    /**
     * Handle a Mollie `failed` status: transitions the contract to FAILED.
     */
    public function handlePaymentFailed(string $providerOrderId, string $reason): FulfillmentResult;

    /**
     * Handle a Mollie `expired` status: transitions the contract to EXPIRED.
     */
    public function handlePaymentExpired(string $providerOrderId): FulfillmentResult;

    /**
     * Handle a Mollie `canceled` status: transitions the contract to CANCELLED.
     */
    public function handlePaymentCanceled(string $providerOrderId, string $reason): FulfillmentResult;

    /**
     * Handle a Mollie `authorized` status (two-step/manual capture): transitions the contract
     * from NOT_FINISHED/PENDING to AUTHORIZED, the state an admin capture or void of the
     * authorization starts from. Deliberately
     * does not set OXPAID or fulfill the order — funds are not captured yet.
     */
    public function handlePaymentAuthorized(
        string $providerOrderId,
        MollieConfirmedFigures $figures,
    ): FulfillmentResult;
}
