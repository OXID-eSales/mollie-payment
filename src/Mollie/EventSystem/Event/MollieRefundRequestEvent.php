<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\EventSystem\Event;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;

/**
 * Admin-initiated refund request (full or partial). Mirrors PayPal's
 * `PayPalRefundRequestEvent` shape: carries the contract directly (translated from the abstract
 * request event's `EventContext` by {@see \OxidEsales\Payments\Mollie\EventSystem\Translator\MollieEventTranslator})
 * and exposes mutable result fields the handler fills in after calling {@see \OxidEsales\Payments\Mollie\Service\RefundServiceInterface}.
 *
 * Story 3 (Sprint 9): Added `description` field for admin audit trail.
 */
final class MollieRefundRequestEvent implements EventInterface
{
    private ?string $refundId = null;
    private ?string $errorCode = null;
    private ?string $errorMessage = null;

    public function __construct(
        public readonly PaymentContractInterface $contract,
        public readonly ?float $amount = null,
        public readonly ?string $reason = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $description = null,
    ) {
    }

    public function setResult(?string $refundId, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $this->refundId = $refundId;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    public function getRefundId(): ?string
    {
        return $this->refundId;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function isSuccess(): bool
    {
        return $this->refundId !== null && $this->errorCode === null;
    }
}
