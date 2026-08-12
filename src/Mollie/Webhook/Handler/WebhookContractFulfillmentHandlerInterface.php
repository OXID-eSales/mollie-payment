<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

/**
 * Bridges verified Mollie webhook status changes to the contract state machine.
 *
 * Each method looks up the contract by providerOrderId (the Mollie payment id), advances it via
 * named transitions only (never setState()), records an audit transaction, and mirrors terminal
 * transitions onto the linked oxorder.
 *
 * Every method reports what it did as a {@see FulfillmentOutcome}. Sprint 11 Story 1 replaced the
 * previous tri-state `?bool` because `false` conflated "already done, nothing to do" with "tried and
 * failed" — and the caller answered Mollie `HTTP 200` for both, so a failed fulfilment was never
 * retried. See {@see FulfillmentOutcome} for the mapping onto HTTP status codes.
 */
interface WebhookContractFulfillmentHandlerInterface
{
    /**
     * Handle a Mollie `paid` status: climbs the contract ladder (PENDING → READY_TO_COMMIT →
     * COMMITTED) as far as it currently allows, then fulfills it.
     */
    public function handlePaymentPaid(string $providerOrderId): FulfillmentOutcome;

    /**
     * Handle a Mollie `failed` status: transitions the contract to FAILED.
     */
    public function handlePaymentFailed(string $providerOrderId, string $reason): FulfillmentOutcome;

    /**
     * Handle a Mollie `expired` status: transitions the contract to EXPIRED.
     */
    public function handlePaymentExpired(string $providerOrderId): FulfillmentOutcome;

    /**
     * Handle a Mollie `canceled` status: transitions the contract to CANCELLED.
     */
    public function handlePaymentCanceled(string $providerOrderId, string $reason): FulfillmentOutcome;

    /**
     * Handle a Mollie `authorized` status (two-step/manual capture): transitions the contract
     * from NOT_FINISHED/PENDING to AUTHORIZED, the state {@see \OxidEsales\Payments\Mollie\Service\CaptureService}
     * and {@see \OxidEsales\Payments\Mollie\Service\CancelAuthorizationService} require. Deliberately
     * does not set OXPAID or fulfill the order — funds are not captured yet.
     */
    public function handlePaymentAuthorized(string $providerOrderId): FulfillmentOutcome;
}
