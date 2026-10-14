<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

/**
 * What a {@see WebhookContractFulfillmentHandlerInterface} method did, WITH the contract it did it
 * to (review 2026-10-14, A-M2: the bare {@see FulfillmentOutcome} enum discarded the contract id
 * the fulfilment handler had already loaded, forcing {@see AbstractMollieWebhookHandler} to run a
 * second repository lookup per delivery purely to recover it).
 *
 * {@see $contractId} is null exactly when no contract was involved: the not-found case, and the
 * stale-retry give-up where only the id from the exception may be known.
 */
final readonly class FulfillmentResult
{
    private function __construct(
        public FulfillmentOutcome $outcome,
        public ?string $contractId = null,
    ) {
    }

    public static function contractNotFound(): self
    {
        return new self(FulfillmentOutcome::ContractNotFound);
    }

    public static function acted(?string $contractId): self
    {
        return new self(FulfillmentOutcome::Acted, self::normalize($contractId));
    }

    public static function noOp(?string $contractId): self
    {
        return new self(FulfillmentOutcome::NoOp, self::normalize($contractId));
    }

    public static function failed(?string $contractId = null): self
    {
        return new self(FulfillmentOutcome::Failed, self::normalize($contractId));
    }

    private static function normalize(?string $contractId): ?string
    {
        return $contractId === '' ? null : $contractId;
    }
}
