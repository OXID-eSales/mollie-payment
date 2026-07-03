<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller;

use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Mollie\Controller\PaymentController;

/**
 * Testable subclass: overrides every Registry-touching seam so execute() can be exercised
 * as a pure unit — no OXID session/config/exit() involved. Shared by
 * {@see MolliePaymentControllerTest} (Story 2) and {@see MollieRedirectTest} (Story 4).
 */
final class TestableMolliePaymentController extends PaymentController
{
    /** @var list<string> */
    public array $redirectedTo = [];
    public bool $delegatedToParent = false;
    public bool $unavailableErrorShown = false;
    public bool $invalidUserDataErrorShown = false;

    public function __construct(
        private readonly string $paymentId,
        private readonly ?EventDispatcherInterface $dispatcher,
        private readonly bool $userDataValid = true,
        private readonly ?string $selectedMollieMethod = null,
    ) {
        // Intentionally does NOT call parent::__construct() — no OXID bootstrap needed.
    }

    /**
     * Exposes the real (non-overridden) buildCheckoutContext() for Story 5's mollieMethod
     * assertion — the other tests in this file exercise the overridden minimal version above.
     */
    public function realBuildCheckoutContext(string $paymentId): EventContext
    {
        return parent::buildCheckoutContext($paymentId);
    }

    protected function selectedMollieMethod(): ?string
    {
        return $this->selectedMollieMethod;
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

    protected function resolveDispatcher(): ?EventDispatcherInterface
    {
        return $this->dispatcher;
    }

    protected function buildCheckoutContext(string $paymentId): EventContext
    {
        return new EventContext(['paymentId' => $paymentId]);
    }

    protected function redirect(string $url): void
    {
        $this->redirectedTo[] = $url;
    }

    protected function showCheckoutUnavailableError(): void
    {
        $this->unavailableErrorShown = true;
    }

    protected function delegateToParent(): mixed
    {
        $this->delegatedToParent = true;
        return null;
    }
}
