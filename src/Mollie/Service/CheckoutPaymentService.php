<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;

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
    ) {
    }

    public function buildCreatePaymentRequest(
        PaymentContractInterface $contract,
        ?string $method,
        string $redirectUrl,
    ): CreatePaymentRequest {
        return new CreatePaymentRequest(
            amount: MollieAmountDto::fromComponents($contract->getCurrency(), $contract->getAmount()),
            description: $this->buildDescription($contract),
            redirectUrl: $redirectUrl,
            webhookUrl: $this->config->getWebhookUrl(),
            method: $method,
            metadata: ['contract_id' => (string) ($contract->getId() ?? '')],
            captureMode: $this->config->getCaptureMode(),
        );
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
