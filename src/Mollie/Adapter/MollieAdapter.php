<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Capture;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use OxidEsales\Payments\Mollie\Adapter\Dto\CaptureRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MethodsListRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieLineDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;
use OxidEsales\Payments\Mollie\Adapter\Exception\CaptureNotSupportedException;

/**
 * The one SDK-aware class: translates between the module's DTOs and the Mollie SDK. Everything
 * above and below this class is DTO-typed; every SDK ApiException is converted to a domain
 * exception so no Mollie SDK type crosses the adapter boundary.
 */
final class MollieAdapter implements
    MolliePaymentsAdapterInterface,
    MollieCaptureAdapterInterface,
    MollieRefundAdapterInterface,
    MollieWebhookAdapterInterface,
    MollieMethodsAdapterInterface
{
    public function __construct(
        private readonly MollieApiClient $client,
    ) {
    }

    public function createPayment(CreatePaymentRequest $request): MolliePaymentDto
    {
        try {
            $payment = $this->client->payments->create($this->buildCreateBody($request));
        } catch (ApiException $exception) {
            throw MollieExceptionConverter::convert($exception);
        }

        return $this->mapPayment($payment);
    }

    public function getPayment(string $paymentId): MolliePaymentDto
    {
        try {
            $payment = $this->client->payments->get($paymentId);
        } catch (ApiException $exception) {
            throw MollieExceptionConverter::convert($exception);
        }

        return $this->mapPayment($payment);
    }

    public function cancelPayment(string $paymentId): MolliePaymentDto
    {
        try {
            $payment = $this->client->payments->cancel($paymentId);
        } catch (ApiException $exception) {
            throw MollieExceptionConverter::convert($exception);
        }

        return $this->mapPayment($payment);
    }

    public function fetchByWebhookId(string $paymentId): MolliePaymentDto
    {
        // Verification IS the fetch: re-read the payment over TLS with our secret key.
        return $this->getPayment($paymentId);
    }

    public function createCapture(CaptureRequest $request): MollieCaptureDto
    {
        try {
            $payment = $this->client->payments->get($request->paymentId);
        } catch (ApiException $exception) {
            throw MollieExceptionConverter::convert($exception);
        }

        if ((string) $payment->status !== MollieStatusMapper::STATUS_AUTHORIZED) {
            throw new CaptureNotSupportedException(sprintf(
                'Payment %s is not authorized (status: %s); only two-step authorized payments can be captured.',
                $request->paymentId,
                (string) $payment->status,
            ));
        }

        try {
            $capture = $this->client->paymentCaptures->createForId(
                $request->paymentId,
                $this->buildCaptureBody($request),
            );
        } catch (ApiException $exception) {
            throw MollieExceptionConverter::convert($exception);
        }

        return $this->mapCapture($capture);
    }

    public function createRefund(RefundRequest $request): MollieRefundDto
    {
        $body = ['amount' => $request->amount->toMollieArray()];
        if ($request->description !== null && $request->description !== '') {
            $body['description'] = $request->description;
        }

        try {
            $refund = $this->client->paymentRefunds->createForId($request->paymentId, $body);
        } catch (ApiException $exception) {
            throw MollieExceptionConverter::convert($exception);
        }

        return $this->mapRefund($refund);
    }

    public function getRefund(string $paymentId, string $refundId): MollieRefundDto
    {
        try {
            $refund = $this->client->paymentRefunds->getForId($paymentId, $refundId);
        } catch (ApiException $exception) {
            throw MollieExceptionConverter::convert($exception);
        }

        return $this->mapRefund($refund);
    }

    public function listRefunds(string $paymentId): array
    {
        try {
            $collection = $this->client->paymentRefunds->listForId($paymentId);
        } catch (ApiException $exception) {
            throw MollieExceptionConverter::convert($exception);
        }

        return MollieCollectionMapper::map(
            $collection,
            Refund::class,
            fn (Refund $refund): MollieRefundDto => $this->mapRefund($refund),
        );
    }

    public function listCaptures(string $paymentId): array
    {
        try {
            $collection = $this->client->paymentCaptures->listForId($paymentId);
        } catch (ApiException $exception) {
            throw MollieExceptionConverter::convert($exception);
        }

        return MollieCollectionMapper::map(
            $collection,
            Capture::class,
            fn (Capture $capture): MollieCaptureDto => $this->mapCapture($capture),
        );
    }

    public function listActiveMethods(MethodsListRequest $request): array
    {
        return (new MollieMethodsFetcher($this->client))->listActiveMethods($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreateBody(CreatePaymentRequest $request): array
    {
        return [
            'amount' => $request->amount->toMollieArray(),
            'description' => $request->description,
            'redirectUrl' => $request->redirectUrl,
        ]
            + $this->createBodyMethodFields($request)
            + $this->createBodyExtraFields($request)
            + $this->createBodyOrderData($request);
    }

    /**
     * Method + card-token. A card token implies a card payment — force the method and attach the
     * token so Mollie charges the tokenized card inline (no hosted method-selection page).
     *
     * @return array<string, mixed>
     */
    private function createBodyMethodFields(CreatePaymentRequest $request): array
    {
        if ($request->cardToken !== null && $request->cardToken !== '') {
            return ['method' => 'creditcard', 'cardToken' => $request->cardToken];
        }
        if ($request->method !== null && $request->method !== '') {
            return ['method' => $request->method];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function createBodyExtraFields(CreatePaymentRequest $request): array
    {
        $fields = [];
        if ($request->webhookUrl !== null && $request->webhookUrl !== '') {
            $fields['webhookUrl'] = $request->webhookUrl;
        }
        if ($request->metadata !== []) {
            $fields['metadata'] = $request->metadata;
        }
        if ($request->captureMode === 'manual') {
            $fields['captureMode'] = 'manual';
        }

        return $fields;
    }

    /**
     * Orders-API data for pay-later methods (Klarna, Riverty, …). Only present when populated.
     *
     * @return array<string, mixed>
     */
    private function createBodyOrderData(CreatePaymentRequest $request): array
    {
        $data = [];
        if ($request->billingAddress !== null) {
            $data['billingAddress'] = $request->billingAddress->toMollieArray();
        }
        if ($request->shippingAddress !== null) {
            $data['shippingAddress'] = $request->shippingAddress->toMollieArray();
        }
        if ($request->lines !== []) {
            $data['lines'] = array_map(
                static fn (MollieLineDto $line): array => $line->toMollieArray(),
                $request->lines,
            );
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCaptureBody(CaptureRequest $request): array
    {
        if ($request->amount === null) {
            return [];
        }

        return ['amount' => $request->amount->toMollieArray()];
    }

    private function mapPayment(Payment $payment): MolliePaymentDto
    {
        return new MolliePaymentDto(
            (string) $payment->id,
            (string) $payment->status,
            MollieValueMapper::toAmount($payment->amount),
            $payment->getCheckoutUrl(),
            MollieValueMapper::toNullableString($payment->method),
            MollieValueMapper::toMetadata($payment->metadata),
            $payment->getAmountRefunded(),
            $payment->getAmountRemaining(),
            MollieValueMapper::toNullableString($payment->redirectUrl),
            MollieValueMapper::toNullableString($payment->webhookUrl),
            createdAt: MollieValueMapper::toNullableString($payment->createdAt),
        );
    }

    private function mapRefund(Refund $refund): MollieRefundDto
    {
        return new MollieRefundDto(
            (string) $refund->id,
            (string) $refund->paymentId,
            MollieValueMapper::toAmount($refund->amount),
            (string) $refund->status,
            MollieValueMapper::toNullableString($refund->createdAt),
        );
    }

    private function mapCapture(Capture $capture): MollieCaptureDto
    {
        return new MollieCaptureDto(
            (string) $capture->id,
            (string) $capture->paymentId,
            MollieValueMapper::toAmount($capture->amount),
            (string) $capture->status,
            MollieValueMapper::toNullableString($capture->createdAt),
        );
    }
}
