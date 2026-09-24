<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Service\AbandonedAttemptCleanup;
use OxidEsales\Payments\Mollie\Service\InFlightCheckoutReplay;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Controller\MollieOrderController;
use OxidEsales\Payments\Mollie\Service\ContractTokenService;
use OxidEsales\Payments\Mollie\Service\Return\MollieReturnResolver;

/**
 * Testable subclass: overrides every Registry/container-touching seam so both execute() and
 * checkoutReturn() can be exercised as pure units.
 */
final class TestableMollieOrderController extends MollieOrderController
{
    public const LIVE_BASKET_HASH = 'live-basket-hash';

    /** @var list<string> */
    public array $redirectedTo = [];
    public bool $delegatedToParent = false;
    public bool $unavailableErrorShown = false;
    public bool $pendingReturn = false;
    /** @var list<string> Redirect targets BASKET_ITEMS_CHANGED_ERROR was shown for. */
    public array $basketErrorsShown = [];

    /** STRP-171 - the payment-base service that retires an abandoned attempt. */
    public ?AbandonedAttemptCleanup $attemptCleaner = null;

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
        private readonly bool $termsAccepted = true,
        private readonly bool $challengeValid = true,
        private readonly bool $basketEmpty = false,
        private readonly ?InFlightCheckoutReplay $inFlightReplay = null,
    ) {
        // Intentionally does NOT call parent::__construct() — no OXID bootstrap needed.
    }

    protected function currentBasketSummaryHash(): string
    {
        // The real seam hashes the live session basket (Registry-backed).
        return self::LIVE_BASKET_HASH;
    }

    protected function basketRedirectTarget(): string
    {
        return $this->basketEmpty ? 'basket' : 'order';
    }

    protected function showBasketChangedError(string $redirect): void
    {
        $this->basketErrorsShown[] = $redirect;
    }

    protected function warnBasketHashMissing(): void
    {
        // The real seam logs core's warning (Registry-backed) — silent in unit tests.
    }

    protected function confirmsTermsAndConditions(): bool
    {
        // The real seam calls core validateTermsAndConditions() (Registry-backed).
        return $this->termsAccepted;
    }

    protected function passesSessionChallenge(): bool
    {
        // The real seam calls Session::checkSessionChallenge() (Registry-backed).
        return $this->challengeValid;
    }

    protected function resolveCheckoutReturnResponder(): CheckoutReturnResponder
    {
        return $this->responder ?? throw new \RuntimeException('No responder configured for this test');
    }

    protected function resolveService(string $className): ?object
    {
        return match ($className) {
            ContractTokenService::class => $this->tokenService,
            ContractRepositoryInterface::class => $this->contractRepository,
            MollieReturnResolver::class => $this->resolver,
            EventDispatcherInterface::class => $this->dispatcher,
            AbandonedAttemptCleanup::class => $this->attemptCleaner,
            InFlightCheckoutReplay::class => $this->inFlightReplay,
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

    protected function returnIsPending(PaymentContractInterface $contract): bool
    {
        return $this->pendingReturn;
    }
}
