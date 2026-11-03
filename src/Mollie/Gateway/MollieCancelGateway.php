<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Gateway;

use OxidEsales\PaymentBase\Payment\CancelGatewayInterface;
use OxidEsales\PaymentBase\Payment\CancelRequest;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Void of an open Mollie authorization for payment-base's money runner (VR-22 A6). Nothing is booked: Mollie's
 * `canceled` webhook cancels the contract and its order (`WebhookContractFulfillmentHandler`).
 */
final class MollieCancelGateway implements CancelGatewayInterface
{
    public function __construct(
        private readonly MolliePaymentsAdapterInterface $payments,
        private readonly GatewayRefusals $refusals,
    ) {
    }

    public function providerName(): string
    {
        return MollieDefinitions::PROVIDER_NAME;
    }

    public function cancel(CancelRequest $request): void
    {
        $paymentId = $request->contract->getProviderOrderId();
        if ($paymentId === null || $paymentId === '') {
            throw $this->refusals->noPayment();
        }

        try {
            $this->payments->cancelPayment($paymentId);
        } catch (MollieAdapterException) {
            throw $this->refusals->cancelFailed();
        }
    }
}
