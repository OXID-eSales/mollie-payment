<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Gateway;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Payment\RefundGatewayInterface;
use OxidEsales\PaymentBase\Payment\RefundRequest;
use OxidEsales\PaymentBase\Payment\RefundResult;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest as MollieRefundRequest;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Refund of a captured Mollie payment for payment-base's money runner (VR-22 A6). The runner owns policy, idempotency,
 * the order lock and the booking (`ProviderRefundLedger`, by the refund id returned here); this class only talks to
 * Mollie. A transport failure is not answered: the runner then keeps the claim and retries with the same key.
 */
final class MollieRefundGateway implements RefundGatewayInterface
{
    private const STATUS_REFUNDED = 'refunded';
    private const STATUSES_NOT_RUN = ['failed', 'canceled'];

    public function __construct(
        private readonly MollieRefundAdapterInterface $refunds,
        private readonly MolliePaymentsAdapterInterface $payments,
        private readonly GatewayRefusals $refusals,
        private readonly GatewayTextRules $textRules,
    ) {
    }

    public function providerName(): string
    {
        return MollieDefinitions::PROVIDER_NAME;
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $paymentId = $request->contract->getProviderOrderId();
        if ($paymentId === null || $paymentId === '') {
            throw $this->refusals->noPayment();
        }
        $refusal = $this->textRules->refusalFor('refundDescription', $request->description);
        if ($refusal !== null) {
            throw $refusal;
        }

        try {
            $refund = $this->refunds->createRefund(new MollieRefundRequest(
                $paymentId,
                MollieAmountDto::fromComponents($request->currency, $request->amount),
                $request->reason,
                $request->idempotencyKey,
                $request->description,
            ));
        } catch (MollieAdapterException) {
            throw $this->whyRefused($paymentId);
        }

        return new RefundResult($refund->id, (float) $refund->amount->value, $this->statusOf($refund));
    }

    /**
     * A manual-capture payment whose capture Mollie is still settling cannot be refunded yet: say so, the admin
     * retries in a moment. If Mollie cannot be asked why either, the refusal stays the plain one.
     */
    private function whyRefused(string $paymentId): AdminActionRefusedException
    {
        try {
            $settling = $this->payments->getPayment($paymentId)->status === MollieStatusMapper::STATUS_AUTHORIZED;
        } catch (MollieAdapterException) {
            $settling = false;
        }

        return $settling ? $this->refusals->refundSettling() : $this->refusals->refundFailed();
    }

    private function statusOf(MollieRefundDto $refund): string
    {
        if ($refund->status === self::STATUS_REFUNDED) {
            return RefundResult::SUCCEEDED;
        }

        return in_array($refund->status, self::STATUSES_NOT_RUN, true) ? RefundResult::FAILED : RefundResult::PENDING;
    }
}
