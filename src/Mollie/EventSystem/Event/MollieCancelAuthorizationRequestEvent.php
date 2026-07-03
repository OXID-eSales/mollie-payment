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
 * Admin-initiated cancel-authorization request. See {@see MollieRefundRequestEvent} for the
 * shape rationale. Carries no amount — cancelling an authorization is all-or-nothing.
 */
final class MollieCancelAuthorizationRequestEvent implements EventInterface
{
    private bool $success = false;
    private ?string $errorCode = null;
    private ?string $errorMessage = null;

    public function __construct(
        public readonly PaymentContractInterface $contract,
        public readonly ?string $reason = null,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    public function setResult(bool $success, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $this->success = $success;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
