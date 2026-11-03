<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Gateway;

use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGatewayInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentState;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentStatus;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * What payment-base asks Mollie about a payment by Mollie's own id (VR-23 T1): what it holds, and how money that
 * reached a contract which can no longer take it goes back (`LateMoneyResolver`, the stale-order cleanup). A payment
 * of a replaced attempt or of a cancelled contract is not necessarily the contract's current one, hence by id.
 * Failures are not caught: the resolver raises the "payment at risk" flag and answers the webhook 200.
 */
final class MollieProviderPaymentGateway implements ProviderPaymentGatewayInterface
{
    private const STATUS_PAID = 'paid';
    private const REFUND_DESCRIPTION = 'Payment received after the order was closed';

    public function __construct(
        private readonly MolliePaymentsAdapterInterface $payments,
        private readonly MollieRefundAdapterInterface $refunds,
    ) {
    }

    public function provider(): string
    {
        return MollieDefinitions::PROVIDER_NAME;
    }

    public function statusOf(string $providerPaymentId): ProviderPaymentStatus
    {
        $payment = $this->payments->getPayment($providerPaymentId);

        return new ProviderPaymentStatus(
            $this->stateOf($payment->status),
            $providerPaymentId,
            $payment->amount->value,
            $payment->amount->currency,
        );
    }

    public function refund(string $providerPaymentId, string $idempotencyKey): string
    {
        $payment = $this->payments->getPayment($providerPaymentId);

        return $this->refunds->createRefund(new RefundRequest(
            paymentId: $providerPaymentId,
            amount: $payment->amount,
            idempotencyKey: $idempotencyKey,
            description: self::REFUND_DESCRIPTION,
        ))->id;
    }

    public function release(string $providerPaymentId, string $idempotencyKey): void
    {
        $this->payments->releaseAuthorization($providerPaymentId);
    }

    private function stateOf(string $mollieStatus): ProviderPaymentState
    {
        return match ($mollieStatus) {
            self::STATUS_PAID => ProviderPaymentState::Paid,
            MollieStatusMapper::STATUS_AUTHORIZED => ProviderPaymentState::Authorized,
            default => ProviderPaymentState::NoMoney,
        };
    }
}
