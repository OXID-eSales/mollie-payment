<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
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

        $method = $context->get('mollieMethod');
        $redirectUrl = $this->buildRedirectUrl($contract);

        $request = $this->checkoutPaymentService->buildCreatePaymentRequest(
            $contract,
            is_string($method) && $method !== '' ? $method : null,
            $redirectUrl,
        );

        try {
            $payment = $this->paymentsAdapter->createPayment($request);
        } catch (MollieAdapterException $e) {
            $this->failContract($contract, $e);
            return;
        }

        // F14 (open redirect) parity: never redirect a shopper to a checkoutUrl host we haven't
        // verified belongs to Mollie — see MollieRedirectUrlValidator's docblock for the threat
        // model. Treated the same as a create-payment failure: fail the contract, leave
        // `checkoutUrl` unset on the context.
        if ($payment->checkoutUrl === null || !$this->redirectUrlValidator->isAllowed($payment->checkoutUrl)) {
            $this->failUntrustedRedirectHost($contract, $payment->id);
            return;
        }

        $contract->setProvider(MollieDefinitions::PROVIDER_NAME, $payment->id, $payment->checkoutUrl);
        $contract->setMetadata(self::METADATA_MOLLIE_PAYMENT_ID, $payment->id);
        $contract->setMetadata(self::METADATA_MOLLIE_CHECKOUT_URL, $payment->checkoutUrl);
        $this->contractRepository->save($contract);

        $context->set('checkoutUrl', $payment->checkoutUrl);
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
