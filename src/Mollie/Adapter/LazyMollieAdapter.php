<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use Closure;
use OxidEsales\Payments\Mollie\Adapter\Dto\CaptureRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MethodsListRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieMethodDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;

/**
 * Lazy decorator over the real {@see MollieAdapter}. Credentials aren't resolvable at container
 * compile time, so the SDK client is built on first use and reused for the rest of the request
 * (one client / one auth). All five segregated interfaces alias this single shared instance.
 * Mirrors PayPal's LazyPayPalAdapter.
 */
final class LazyMollieAdapter implements
    MolliePaymentsAdapterInterface,
    MollieCaptureAdapterInterface,
    MollieRefundAdapterInterface,
    MollieWebhookAdapterInterface,
    MollieMethodsAdapterInterface
{
    private ?MollieAdapter $adapter = null;

    /**
     * @param Closure(): MollieAdapter $adapterFactory
     */
    public function __construct(
        private readonly Closure $adapterFactory,
    ) {
    }

    public function createPayment(CreatePaymentRequest $request): MolliePaymentDto
    {
        return $this->adapter()->createPayment($request);
    }

    public function getPayment(string $paymentId): MolliePaymentDto
    {
        return $this->adapter()->getPayment($paymentId);
    }

    public function cancelPayment(string $paymentId): MolliePaymentDto
    {
        return $this->adapter()->cancelPayment($paymentId);
    }

    public function fetchByWebhookId(string $paymentId): MolliePaymentDto
    {
        return $this->adapter()->fetchByWebhookId($paymentId);
    }

    public function createCapture(CaptureRequest $request): MollieCaptureDto
    {
        return $this->adapter()->createCapture($request);
    }

    public function createRefund(RefundRequest $request): MollieRefundDto
    {
        return $this->adapter()->createRefund($request);
    }

    public function getRefund(string $paymentId, string $refundId): MollieRefundDto
    {
        return $this->adapter()->getRefund($paymentId, $refundId);
    }

    public function listRefunds(string $paymentId): array
    {
        return $this->adapter()->listRefunds($paymentId);
    }

    public function listCaptures(string $paymentId): array
    {
        return $this->adapter()->listCaptures($paymentId);
    }

    public function listActiveMethods(MethodsListRequest $request): array
    {
        return $this->adapter()->listActiveMethods($request);
    }

    private function adapter(): MollieAdapter
    {
        return $this->adapter ??= ($this->adapterFactory)();
    }
}
