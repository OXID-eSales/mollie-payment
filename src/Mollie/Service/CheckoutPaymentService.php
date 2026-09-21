<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAddressDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use Psr\Log\LoggerInterface;

/**
 * Assembles the {@see CreatePaymentRequest} sent to Mollie's create-payment API.
 *
 * The order number (minted by payment-base's shared EarlyOrderCreationHandler, priority 90,
 * before this service ever runs) is used as the description so it is visible in the Mollie
 * dashboard; falls back to the contract id if the order number is not yet available.
 */
final class CheckoutPaymentService implements CheckoutPaymentServiceInterface
{
    private const METADATA_ORDER_NUMBER = 'order_number';

    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly MollieOrderDataProviderInterface $orderData,
        private readonly MollieWebhookUrlProviderInterface $webhookUrlProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function buildCreatePaymentRequest(
        PaymentContractInterface $contract,
        ?string $method,
        string $redirectUrl,
        ?string $cardToken = null,
    ): CreatePaymentRequest {
        $hasCardToken = $cardToken !== null && $cardToken !== '';
        // A card token pins the method to creditcard; otherwise the caller's method is passed through.
        $effectiveMethod = $hasCardToken ? 'creditcard' : $method;

        [$billingAddress, $lines] = $this->orderDataFor($effectiveMethod, $contract);

        // Safety: a pay-later method without the required order data would 422. Rather than fail the
        // shopper, drop the forced method so Mollie presents its hosted page (which collects the
        // address itself). Normal logged-in checkouts always have a complete address, so this is rare.
        //
        // Sprint 11 Story 8 (F15): this silently replaces the method the shopper explicitly chose, so
        // it is logged. An address-mapping problem affecting every Klarna order would otherwise be
        // invisible — the checkout would just quietly stop honouring the selection.
        $needsOrderData = $effectiveMethod !== null && MollieDefinitions::requiresOrderData($effectiveMethod);
        if ($needsOrderData && $billingAddress === null) {
            $this->logger->warning(
                '[CheckoutPaymentService] dropping the shopper\'s pay-later method: required billing '
                . 'address is missing or incomplete; Mollie will present its own method page',
                ['contractId' => (string) ($contract->getId() ?? ''), 'droppedMethod' => $effectiveMethod],
            );
            $effectiveMethod = null;
        }

        return new CreatePaymentRequest(
            amount: MollieAmountDto::fromComponents($contract->getCurrency(), $contract->getAmount()),
            description: $this->buildDescription($contract),
            redirectUrl: $redirectUrl,
            webhookUrl: $this->webhookUrlProvider->getWebhookUrl(),
            method: $effectiveMethod,
            metadata: ['contract_id' => (string) ($contract->getId() ?? '')],
            captureMode: $this->captureModeFor($effectiveMethod),
            cardToken: $hasCardToken ? $cardToken : null,
            billingAddress: $billingAddress,
            shippingAddress: $billingAddress,
            lines: $lines,
        );
    }

    /**
     * Manual capture is decided per method, not per shop.
     *
     * A manual-capture shop still sells via iDEAL, PayPal, bank transfer, … — methods that
     * settle immediately and cannot hold an authorization. Mollie answers 422 ("At least
     * one of the provided payment methods must support captures") when such a method is
     * pinned together with captureMode=manual, so those are created with automatic
     * capture; card and Buy-Now-Pay-Later are authorized first as configured. With no
     * method pinned the shop's mode is passed through: Mollie's hosted page offers every
     * method and drops manual capture itself for the ones that cannot do it (verified
     * live — a manual-capture payment paid via iDEAL comes back with captureMode unset).
     */
    private function captureModeFor(?string $method): string
    {
        $mode = $this->config->getCaptureMode();
        if ($mode !== MollieDefinitions::CAPTURE_MODE_MANUAL || $method === null) {
            return $mode;
        }

        return MollieDefinitions::supportsManualCapture($method)
            ? MollieDefinitions::CAPTURE_MODE_MANUAL
            : MollieDefinitions::CAPTURE_MODE_AUTOMATIC;
    }

    /**
     * Pay-later methods (Klarna, …) need a billing address + reconciled lines from the early-created
     * order. Returns [null, []] for methods that don't, or when the order/address is unavailable.
     *
     * @return array{0: MollieAddressDto|null, 1: list<\OxidEsales\Payments\Mollie\Adapter\Dto\MollieLineDto>}
     */
    private function orderDataFor(?string $method, PaymentContractInterface $contract): array
    {
        if ($method === null || !MollieDefinitions::requiresOrderData($method)) {
            return [null, []];
        }

        $address = $this->orderData->billingAddress();
        if ($address === null || !$address->isComplete()) {
            return [null, []];
        }

        return [
            $address,
            $this->orderData->lines($contract->getCurrency(), $contract->getAmount()),
        ];
    }

    private function buildDescription(PaymentContractInterface $contract): string
    {
        $orderNumber = $contract->getMetadata(self::METADATA_ORDER_NUMBER);
        if (is_scalar($orderNumber) && (string) $orderNumber !== '') {
            return (string) $orderNumber;
        }

        return (string) ($contract->getId() ?? '');
    }
}
