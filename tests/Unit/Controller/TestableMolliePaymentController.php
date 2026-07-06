<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller;

use OxidEsales\Payments\Mollie\Controller\PaymentController;

/**
 * Testable subclass: overrides every Registry-touching seam so validatePayment() can be
 * exercised as a pure unit — no OXID session/config involved.
 */
final class TestableMolliePaymentController extends PaymentController
{
    public bool $invalidUserDataErrorShown = false;
    public bool $delegatedValidatePayment = false;

    public function __construct(
        private readonly string $paymentId,
        private readonly bool $userDataValid = true,
        private readonly mixed $delegateResult = 'order',
    ) {
        // Intentionally does NOT call parent::__construct() — no OXID bootstrap needed.
    }

    protected function getSelectedPaymentId(): string
    {
        return $this->paymentId;
    }

    protected function userDataIsValid(): bool
    {
        return $this->userDataValid;
    }

    protected function showInvalidUserDataError(): void
    {
        $this->invalidUserDataErrorShown = true;
    }

    protected function delegateValidatePayment(): mixed
    {
        $this->delegatedValidatePayment = true;

        return $this->delegateResult;
    }
}
