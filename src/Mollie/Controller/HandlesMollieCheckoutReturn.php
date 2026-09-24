<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Controller\SessionWriterInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\AbandonedAttemptCleanup;
use OxidEsales\Payments\Mollie\Service\ContractTokenService;
use OxidEsales\Payments\Mollie\Service\Return\MollieReturnResolver;
use OxidEsales\Payments\Mollie\Service\Return\PendingReturnProbe;
use RuntimeException;

/**
 * The post-checkout return leg of {@see MollieOrderController}, reachable at
 * `cl=order&fnc=checkoutReturn`.
 *
 * Mollie always redirects back to one `redirectUrl` regardless of outcome (there is no separate
 * cancel URL like PayPal's), so {@see MollieReturnResolver} maps every Mollie payment status onto the
 * shared ReturnResolution and this leg just reacts to success / pending / failure. The webhook remains
 * the source of truth for fulfilment; the return only advances safely.
 *
 * A trait, not a base class: the controller is an OXID class-chain extension (one concrete class per
 * chain slot), and the two legs - submitting the order and coming back from Mollie - are separate
 * responsibilities that share only the controller's service lookup and request reading.
 * Requires the host to provide `resolveService()` and `readRequestParameter()`.
 */
trait HandlesMollieCheckoutReturn
{
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
            // A null order id covers TWO cases the responder can't distinguish here: a hard failure,
            // and a still-open/pending payment left for the webhook (common for PayPal / bank-style
            // methods). Query Mollie once to tell them apart — a pending payment is NOT an error: the
            // order was placed and the webhook will finalize it, so land on thank-you with a notice.
            if ($this->returnIsPending($contract)) {
                return $this->onReturnPending($contract);
            }
            $this->resolveService(AbandonedAttemptCleanup::class)?->retire($contractId);
            return $this->onReturnError('return_not_finalised');
        }

        return 'thankyou';
    }


    protected function resolveCheckoutReturnResponder(): CheckoutReturnResponder
    {
        // resolveService() is generic (T|null) and the container id IS the class name — no
        // instanceof re-check needed here or in the sibling resolvers below.
        return $this->resolveService(CheckoutReturnResponder::class)
            ?? throw new RuntimeException('CheckoutReturnResponder not available');
    }


    private function resolveReturnResolver(): ?ReturnResolverInterface
    {
        return $this->resolveService(MollieReturnResolver::class);
    }


    private function tokenIsValid(string $contractToken, string $contractId): bool
    {
        // Resolve Mollie's CONCRETE token service, not the shared
        // PaymentBase\TokenServiceInterface: that interface is single-valued in the merged DI
        // container and, when another PSP is active, resolves to the wrong provider's HMAC.
        return (bool) $this->resolveService(ContractTokenService::class)
            ?->validateToken($contractToken, $contractId);
    }


    private function loadContract(string $contractId): ?PaymentContractInterface
    {
        return $this->resolveService(ContractRepositoryInterface::class)?->findById($contractId);
    }


    private function onReturnError(string $code): string
    {
        Registry::getLogger()->warning('MollieOrderController: checkout return failed', ['reason' => $code]);
        Registry::getUtilsView()->addErrorToDisplay('MOLLIE_RETURN_' . strtoupper($code));

        return 'payment';
    }


    /**
     * True when the Mollie payment is still open/pending on return (not paid/authorized yet, but not
     * failed) — the webhook will finalize it. Overridable seam; production delegates to
     * {@see PendingReturnProbe}, fail-closed to false when the service is unavailable.
     */
    protected function returnIsPending(PaymentContractInterface $contract): bool
    {
        return (bool) $this->resolveService(PendingReturnProbe::class)?->isPending($contract);
    }


    /**
     * Pending payment on return: the order is placed (NOT_FINISHED) and the webhook will confirm it.
     * Show the thank-you page with a "payment is being processed" notice — never the error screen.
     */
    private function onReturnPending(PaymentContractInterface $contract): string
    {
        Registry::getLogger()->info(
            'MollieOrderController: payment pending on return — order placed, awaiting webhook confirmation',
        );

        $orderId = (string) $contract->getOrderId();
        if ($orderId !== '') {
            $this->resolveService(SessionWriterInterface::class)?->writeSessChallenge($orderId);
        }

        Registry::getUtilsView()->addErrorToDisplay('MOLLIE_RETURN_PENDING');

        return 'thankyou';
    }
}
