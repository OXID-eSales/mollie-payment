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
        if ($effectiveMethod !== null && MollieDefinitions::requiresOrderData($effectiveMethod) && $billingAddress === null) {
            $effectiveMethod = null;
        }

        return new CreatePaymentRequest(
            amount: MollieAmountDto::fromComponents($contract->getCurrency(), $contract->getAmount()),
            description: $this->buildDescription($contract),
            redirectUrl: $redirectUrl,
            webhookUrl: $this->config->getWebhookUrl(),
            method: $effectiveMethod,
            metadata: ['contract_id' => (string) ($contract->getId() ?? '')],
            captureMode: $this->config->getCaptureMode(),
            cardToken: $hasCardToken ? $cardToken : null,
            billingAddress: $billingAddress,
            shippingAddress: $billingAddress,
            lines: $lines,
        );
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
