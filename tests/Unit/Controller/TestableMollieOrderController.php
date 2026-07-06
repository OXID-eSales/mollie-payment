<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller;

use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Controller\MollieOrderController;
use OxidEsales\Payments\Mollie\Service\Return\MollieReturnResolver;

/**
 * Testable subclass: overrides every Registry/container-touching seam so both execute() and
 * checkoutReturn() can be exercised as pure units.
 */
final class TestableMollieOrderController extends MollieOrderController
{
    /** @var list<string> */
    public array $redirectedTo = [];
    public bool $delegatedToParent = false;
    public bool $unavailableErrorShown = false;

    /**
     * @param array<string, string> $requestParams
     */
    public function __construct(
        private readonly array $requestParams,
        private readonly TokenServiceInterface $tokenService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ?ReturnResolverInterface $resolver,
        private readonly ?CheckoutReturnResponder $responder,
        private readonly string $paymentId = '',
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
        // Intentionally does NOT call parent::__construct() — no OXID bootstrap needed.
    }

    protected function resolveCheckoutReturnResponder(): CheckoutReturnResponder
    {
        return $this->responder ?? throw new \RuntimeException('No responder configured for this test');
    }

    protected function resolveService(string $className): ?object
    {
        return match ($className) {
            TokenServiceInterface::class => $this->tokenService,
            ContractRepositoryInterface::class => $this->contractRepository,
            MollieReturnResolver::class => $this->resolver,
            EventDispatcherInterface::class => $this->dispatcher,
            default => null,
        };
    }

    protected function readRequestParameter(string $name): ?string
    {
        $value = $this->requestParams[$name] ?? '';

        return $value !== '' ? $value : null;
    }

    protected function getSelectedPaymentId(): string
    {
        return $this->paymentId;
    }

    protected function buildCheckoutContext(string $paymentId): EventContext
    {
        return new EventContext(['paymentId' => $paymentId]);
    }

    protected function redirect(string $url): void
    {
        $this->redirectedTo[] = $url;
    }

    protected function onCheckoutUnavailable(): string
    {
        $this->unavailableErrorShown = true;

        return 'payment';
    }

    protected function delegateToParent(): ?string
    {
        $this->delegatedToParent = true;

        return null;
    }
}
