<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Controller\HandlesCheckoutReturn;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\Return\MollieReturnResolver;
use RuntimeException;
use Throwable;

/**
 * Registered directly in metadata.php `controllers` (cl=MollieOrderController) — NOT a
 * class-chain extension of OXID's OrderController and NOT tagged `oxid.view_controller`.
 * payment-base's own services.yaml documents why: tagging a controller forces the DI compiler
 * to reflect/autoload it, which re-enters the OXID module class-chain build and causes a
 * "Controller namespace duplication" failure on activation. Registering a brand-new `cl=` key
 * via the metadata controllers map (as ValidationApiController and PayPal's own
 * PayPalOrderController already do in this codebase) sidesteps that entirely.
 *
 * Handles the single post-checkout return leg: Mollie always redirects back to one
 * `redirectUrl` regardless of outcome (there is no separate cancel URL like PayPal's), so
 * {@see MollieReturnResolver} maps every Mollie payment status onto the shared
 * ReturnResolution and this controller just reacts to success/failure.
 */
class MollieOrderController extends FrontendController
{
    use HandlesCheckoutReturn;

    public function checkoutReturn(): string
    {
        $contractId = $this->readRequestParameter('contract_id');
        $contractToken = $this->readRequestParameter('contract_token');
        if ($contractId === null || $contractToken === null) {
            return $this->onReturnError('missing_token');
        }

        if (!$this->tokenIsValid($contractToken, $contractId)) {
            return $this->onReturnError('invalid_token');
        }

        $contract = $this->loadContract($contractId);
        if ($contract === null) {
            return $this->onReturnError('unknown_contract');
        }

        $resolver = $this->resolveReturnResolver();
        if ($resolver === null) {
            return $this->onReturnError('return_service_unavailable');
        }

        $orderId = $this->dispatchCheckoutReturn(
            providerName: MollieDefinitions::PROVIDER_NAME,
            contract: $contract,
            resolver: $resolver,
        );

        if ($orderId === null) {
            // Covers both a hard failure and a still-open payment (idempotently left for the
            // webhook, Sprint 5) — dispatchCheckoutReturn() intentionally does not distinguish
            // the two at this layer; either way there is nothing to commit to yet.
            return $this->onReturnError('return_not_finalised');
        }

        return 'thankyou';
    }

    protected function resolveCheckoutReturnResponder(): CheckoutReturnResponder
    {
        $responder = $this->resolveService(CheckoutReturnResponder::class);

        return $responder instanceof CheckoutReturnResponder
            ? $responder
            : throw new RuntimeException('CheckoutReturnResponder not available');
    }

    private function resolveReturnResolver(): ?ReturnResolverInterface
    {
        $resolver = $this->resolveService(MollieReturnResolver::class);

        return $resolver instanceof ReturnResolverInterface ? $resolver : null;
    }

    private function tokenIsValid(string $contractToken, string $contractId): bool
    {
        $tokenService = $this->resolveService(TokenServiceInterface::class);

        return $tokenService instanceof TokenServiceInterface
            && $tokenService->validateToken($contractToken, $contractId);
    }

    private function loadContract(string $contractId): ?PaymentContractInterface
    {
        $repository = $this->resolveService(ContractRepositoryInterface::class);

        return $repository instanceof ContractRepositoryInterface ? $repository->findById($contractId) : null;
    }

    protected function readRequestParameter(string $name): ?string
    {
        $value = Registry::getRequest()->getRequestParameter($name);
        $value = is_scalar($value) ? (string) $value : '';

        return $value !== '' ? $value : null;
    }

    private function onReturnError(string $code): string
    {
        Registry::getLogger()->warning('MollieOrderController: checkout return failed', ['reason' => $code]);
        Registry::getUtilsView()->addErrorToDisplay('MOLLIE_RETURN_' . strtoupper($code));

        return 'payment';
    }

    /**
     * @param class-string $className
     */
    protected function resolveService(string $className): ?object
    {
        try {
            return ContainerFacade::get($className);
        } catch (Throwable) {
            return null;
        }
    }
}
