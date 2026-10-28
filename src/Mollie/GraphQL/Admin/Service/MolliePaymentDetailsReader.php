<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentSnapshotProviderInterface;
use OxidEsales\Payments\Mollie\Admin\PaymentMethodDescriptor;
use OxidEsales\Payments\Mollie\Admin\PaymentMethodLabelResolver;
use OxidEsales\Payments\Mollie\Admin\ReleaseRule;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieUrlBuilder;

/**
 * The Mollie part of the Payment tab for the admin API. One live read per payment and request: the shared snapshot
 * provider the classic panel uses (memoized per request, a failed read is cached too). The release figures come from
 * {@see ReleaseRule}, the one rule the classic panel and the release action apply.
 */
final class MolliePaymentDetailsReader implements MolliePaymentDetailsReaderInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contracts,
        private readonly MolliePaymentSnapshotProviderInterface $snapshots,
        private readonly PaymentMethodLabelResolver $methodLabels,
        private readonly ReleaseRule $releaseRule,
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly MollieUrlBuilder $urlBuilder,
    ) {
    }

    public function forContract(string $contractId): ?MolliePaymentDetails
    {
        $contract = $this->contracts->findById($contractId);
        if ($contract === null || $contract->getProvider() !== MollieDefinitions::PROVIDER_NAME) {
            return null;
        }
        $paymentId = $this->nonEmpty($contract->getProviderOrderId());
        if ($paymentId === null) {
            return $this->unavailable($contract, null, MolliePaymentDetails::ERROR_NO_PAYMENT);
        }
        $payment = $this->snapshots->snapshot($contract);
        if ($payment === null) {
            return $this->unavailable($contract, $paymentId, MolliePaymentDetails::ERROR_UNREACHABLE);
        }

        return $this->read($contract, $paymentId, $payment);
    }

    private function read(
        PaymentContractInterface $contract,
        string $paymentId,
        MolliePaymentDto $payment
    ): MolliePaymentDetails {
        $method = PaymentMethodDescriptor::fromPayment($payment);

        return new MolliePaymentDetails(
            paymentId: $paymentId,
            dashboardUrl: $this->urlBuilder->paymentUrl($paymentId),
            methodLabel: $method->isKnown() ? $this->methodLabels->label($method) : null,
            methodDetail: $method->detail(),
            mollieStatus: $payment->status,
            amountReleased: $this->releasedAmount($contract),
            amountReleasable: $this->releaseRule->releasableAmount($contract),
            multiCapture: $this->config->isMultiCaptureEnabled(),
            apiError: null,
        );
    }

    /**
     * Fail closed: what only Mollie could say is null, the shop's own record stays.
     */
    private function unavailable(
        PaymentContractInterface $contract,
        ?string $paymentId,
        string $error
    ): MolliePaymentDetails {
        return new MolliePaymentDetails(
            paymentId: $paymentId,
            dashboardUrl: $paymentId === null ? null : $this->urlBuilder->paymentUrl($paymentId),
            methodLabel: null,
            methodDetail: null,
            mollieStatus: null,
            amountReleased: $this->releasedAmount($contract),
            amountReleasable: null,
            multiCapture: $this->config->isMultiCaptureEnabled(),
            apiError: $error,
        );
    }

    private function releasedAmount(PaymentContractInterface $contract): float
    {
        return $this->releaseRule->isReleased($contract) ? $this->releaseRule->releasedAmount($contract) : 0.0;
    }

    private function nonEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
