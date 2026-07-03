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
 * Admin-initiated capture request (full or partial two-step capture). See
 * {@see MollieRefundRequestEvent} for the shape rationale.
 */
final class MollieCaptureRequestEvent implements EventInterface
{
    private ?string $captureId = null;
    private ?string $errorCode = null;
    private ?string $errorMessage = null;

    public function __construct(
        public readonly PaymentContractInterface $contract,
        public readonly ?float $amount = null,
        public readonly ?string $reason = null,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    public function isFullCapture(): bool
    {
        return $this->amount === null;
    }

    public function setResult(?string $captureId, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $this->captureId = $captureId;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    public function getCaptureId(): ?string
    {
        return $this->captureId;
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
        return $this->captureId !== null && $this->errorCode === null;
    }
}
