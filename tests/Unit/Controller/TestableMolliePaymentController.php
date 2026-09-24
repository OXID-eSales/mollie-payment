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
    public array $userDataProblemsShown = [];
    public bool $delegatedValidatePayment = false;

    public function __construct(
        private readonly string $paymentId,
        private readonly array $userDataProblems = [],
        private readonly mixed $delegateResult = 'order',
    ) {
        // Intentionally does NOT call parent::__construct() — no OXID bootstrap needed.
    }

    protected function getSelectedPaymentId(): string
    {
        return $this->paymentId;
    }

    protected function userDataProblems(): array
    {
        return $this->userDataProblems;
    }

    protected function showUserDataProblems(array $messages): void
    {
        $this->userDataProblemsShown = $messages;
    }

    protected function delegateValidatePayment(): mixed
    {
        $this->delegatedValidatePayment = true;

        return $this->delegateResult;
    }
}
