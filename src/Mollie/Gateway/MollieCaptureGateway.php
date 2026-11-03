<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Gateway;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Payment\CaptureGatewayInterface;
use OxidEsales\PaymentBase\Payment\CaptureRequest;
use OxidEsales\PaymentBase\Payment\CaptureResult;
use OxidEsales\Payments\Mollie\Adapter\Dto\CaptureRequest as MollieCaptureRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MollieCaptureAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;

/**
 * Capture of an open Mollie authorization for payment-base's money runner (VR-22 A6). The runner owns policy,
 * idempotency, the order lock and the booking (`ProviderCaptureLedger`, by the capture id returned here); this class
 * only talks to Mollie. The contract follows Mollie's `paid` webhook.
 */
final class MollieCaptureGateway implements CaptureGatewayInterface
{
    public function __construct(
        private readonly MollieCaptureAdapterInterface $captures,
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly GatewayRefusals $refusals,
        private readonly GatewayTextRules $textRules,
    ) {
    }

    public function providerName(): string
    {
        return MollieDefinitions::PROVIDER_NAME;
    }

    public function capture(CaptureRequest $request): CaptureResult
    {
        $paymentId = $request->contract->getProviderOrderId();
        if ($paymentId === null || $paymentId === '') {
            throw $this->refusals->noPayment();
        }
        $this->assertAccepted($request);

        try {
            $capture = $this->captures->createCapture(new MollieCaptureRequest(
                $paymentId,
                MollieAmountDto::fromComponents($request->currency, $request->amount),
                $request->idempotencyKey,
            ));
        } catch (MollieAdapterException) {
            throw $this->refusals->captureFailed();
        }

        return new CaptureResult($capture->id, (float) $capture->amount->value);
    }

    /**
     * @throws AdminActionRefusedException
     */
    private function assertAccepted(CaptureRequest $request): void
    {
        // Without multi-capture the first capture is final: Mollie releases what it left, a second one is a 422.
        if (!$this->config->isMultiCaptureEnabled() && ($request->contract->getCapturedAmount() ?? 0.0) > 0.0) {
            throw $this->refusals->captureFinal();
        }
        $refusal = $this->textRules->refusalFor('captureReason', $request->reason);
        if ($refusal !== null) {
            throw $refusal;
        }
    }
}
