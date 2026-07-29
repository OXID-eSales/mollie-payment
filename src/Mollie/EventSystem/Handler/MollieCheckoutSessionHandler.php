<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\Service\CheckoutPaymentServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieRedirectUrlValidator;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Creates the Mollie payment after the earlier contract/order-creation handlers have run.
 *
 * Priority is deliberately low (10) so {@see MollieContractCreationHandler} (priority 100) and
 * payment-base's shared EarlyOrderCreationHandler (priority 90, triggered synchronously from
 * within the contract-creation handler) populate the contract + order number before this handler
 * attempts the Mollie create-payment call.
 */
final class MollieCheckoutSessionHandler implements HandlerInterface
{
    private const METADATA_MOLLIE_PAYMENT_ID = 'mollie_payment_id';
    private const METADATA_MOLLIE_CHECKOUT_URL = 'mollie_checkout_url';

    public function __construct(
        private readonly CheckoutPaymentServiceInterface $checkoutPaymentService,
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly TokenServiceInterface $tokenService,
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly MollieRedirectUrlValidator $redirectUrlValidator = new MollieRedirectUrlValidator(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return MollieCheckoutSessionRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof MollieCheckoutSessionRequestEvent) {
            return;
        }

        $context = $event->getContext();
        $contract = $context->getContract();
        if ($contract === null) {
            throw new RuntimeException(
                'Mollie checkout session handler: no contract in context — '
                . 'MollieContractCreationHandler must run first.',
            );
        }

        $redirectUrl = $this->buildRedirectUrl($contract);

        // IFRAME-04: an inline-card token (Mollie Components) pins the payment to a card charge.
        // Without it the method stays null and Mollie presents its hosted method-selection page
        // (the classic redirect flow, unchanged).
        $cardToken = $this->readCardToken($context);
        $request = $this->checkoutPaymentService->buildCreatePaymentRequest(
            $contract,
            $cardToken !== null ? 'creditcard' : null,
            $redirectUrl,
            $cardToken,
        );

        try {
            $payment = $this->paymentsAdapter->createPayment($request);
        } catch (MollieAdapterException $e) {
            $this->failContract($contract, $e);
            return;
        }

        // F14 (open redirect) parity: never redirect a shopper to a checkoutUrl host we haven't
        // verified belongs to Mollie — see MollieRedirectUrlValidator's docblock. A null/blank or
        // untrusted destination is treated as a create-payment failure (checkoutUrl left unset).
        $destination = $this->resolveDestination($payment->checkoutUrl, $cardToken, $redirectUrl);
        if ($destination === null) {
            $this->failUntrustedRedirectHost($contract, $payment->id);
            return;
        }

        $contract->setProvider(MollieDefinitions::PROVIDER_NAME, $payment->id, $payment->checkoutUrl ?? $redirectUrl);
        $contract->setMetadata(self::METADATA_MOLLIE_PAYMENT_ID, $payment->id);
        $contract->setMetadata(self::METADATA_MOLLIE_CHECKOUT_URL, $payment->checkoutUrl ?? $redirectUrl);
        $this->contractRepository->save($contract);

        $context->set('checkoutUrl', $destination);
    }

    /**
     * Resolve where to send the shopper after create-payment:
     *  - Mollie returned a checkout URL (hosted method page, or a 3DS/SCA authentication page for
     *    an inline card) → that URL, but only if it is a verified Mollie host (open-redirect guard).
     *  - No checkout URL but this was an inline card charge → the card cleared without 3DS, so send
     *    the shopper to our own return leg (checkoutReturn) to finalize the order.
     *  - No checkout URL on the classic redirect flow → genuine failure.
     * Returns null when no trustworthy destination is available (caller fails the contract).
     */
    private function resolveDestination(?string $mollieCheckoutUrl, ?string $cardToken, string $returnUrl): ?string
    {
        if ($mollieCheckoutUrl !== null && $mollieCheckoutUrl !== '') {
            return $this->redirectUrlValidator->isAllowed($mollieCheckoutUrl) ? $mollieCheckoutUrl : null;
        }

        return $cardToken !== null ? $returnUrl : null;
    }

    /**
     * Read the Mollie Components card token off the event context (set from the storefront
     * request). Returns null for a missing/blank value (classic redirect flow).
     */
    private function readCardToken(EventContext $context): ?string
    {
        $token = $context->get('cardToken');
        if (!is_string($token)) {
            return null;
        }
        $token = trim($token);

        return $token === '' ? null : $token;
    }

    private function buildRedirectUrl(PaymentContractInterface $contract): string
    {
        $contractId = (string) ($contract->getId() ?? '');
        $token = $this->tokenService->generateToken($contractId);
        $shopUrl = rtrim($this->shopAdapter->getShopUrl(), '/');

        return sprintf(
            '%s/index.php?cl=%s&fnc=checkoutReturn&contract_id=%s&contract_token=%s',
            $shopUrl,
            MollieDefinitions::ORDER_CONTROLLER_ID,
            urlencode($contractId),
            urlencode($token),
        );
    }

    private function failContract(PaymentContractInterface $contract, MollieAdapterException $e): void
    {
        $contract->fail('mollie_create_payment_failed: ' . $e->getMessage());
        $this->contractRepository->save($contract);
        $this->logger?->error('MollieCheckoutSessionHandler: create payment failed', [
            'error' => $e->getMessage(),
        ]);
    }

    private function failUntrustedRedirectHost(PaymentContractInterface $contract, string $paymentId): void
    {
        $contract->fail('untrusted_redirect_host: ' . $paymentId);
        $this->contractRepository->save($contract);
        $this->logger?->error('MollieCheckoutSessionHandler: checkoutUrl host not allowed', [
            'paymentId' => $paymentId,
        ]);
    }
}
