<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Service\MollieUrlBuilder;
use OxidEsales\Payments\Mollie\Service\TransactionHistoryServiceInterface;

/**
 * Assembles the flat, Twig-consumable view-data array for the Mollie admin panel body
 * ({@see MolliePaymentPanelProvider::build()}): contract identity, capture/refund bounds (Story
 * 2), live transaction history, a Mollie dashboard deep-link, and any pending admin validation
 * message (Story 3, consumed exactly once per render).
 */
class MolliePanelViewDataBuilder
{
    public function __construct(
        private readonly ContractRepositoryInterface $contracts,
        private readonly TransactionHistoryServiceInterface $transactionHistory,
        private readonly AdminActionBoundsInterface $bounds,
        private readonly MollieUrlBuilder $urlBuilder,
        private readonly AdminValidationFeedbackInterface $validationFeedback,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Order $order): array
    {
        $orderId = (string) $order->getId();
        $validationErrors = $this->validationFeedback->consume($orderId);
        $contract = $this->contracts->findByOrderId($orderId);

        if ($contract === null) {
            return $this->empty($orderId, 'No Mollie contract is linked to this order.', $validationErrors);
        }

        return $this->fromContract($orderId, $contract, $order, $validationErrors);
    }

    /**
     * @param list<string> $validationErrors
     * @return array<string, mixed>
     */
    private function fromContract(
        string $orderId,
        PaymentContractInterface $contract,
        Order $order,
        array $validationErrors,
    ): array {
        $providerOrderId = $contract->getProviderOrderId();
        $captureBound = $this->bounds->captureBound($contract);
        $refundBound = $this->bounds->refundBound($contract);

        return [
            'orderId' => $orderId,
            'orderNumber' => $this->readOrderField($order, 'oxordernr'),
            'paymentType' => $this->readOrderField($order, 'oxpaymenttype'),
            'contractId' => (string) $contract->getId(),
            'providerOrderId' => $providerOrderId,
            'contractState' => $contract->getStateValue(),
            'currency' => $contract->getCurrency(),
            'capturedAmount' => $this->money($contract->getCapturedAmount()),
            'refundedAmount' => $this->money($contract->getRefundedAmount()),
            'captureBound' => $captureBound,
            'captureBoundFormatted' => $this->money($captureBound),
            'refundBound' => $refundBound,
            'refundBoundFormatted' => $this->money($refundBound),
            'isCapturable' => $contract->getState()->isAuthorized() && $captureBound > 0.0,
            'isRefundable' => $contract->getState()->isFulfilled() && $refundBound > 0.0,
            'isCancellable' => $contract->getState()->isAuthorized(),
            'dashboardUrl' => $this->dashboardUrl($providerOrderId),
            'transactions' => $this->transactionHistory->fetch($contract),
            'errorMessage' => null,
            'validationErrors' => $validationErrors,
        ];
    }

    private function dashboardUrl(?string $providerOrderId): ?string
    {
        if ($providerOrderId === null || $providerOrderId === '') {
            return null;
        }

        return $this->urlBuilder->paymentUrl($providerOrderId);
    }

    /**
     * @param list<string> $validationErrors
     * @return array<string, mixed>
     */
    private function empty(string $orderId, string $message, array $validationErrors): array
    {
        return [
            'orderId' => $orderId,
            'orderNumber' => '',
            'paymentType' => '',
            'contractId' => '',
            'providerOrderId' => null,
            'contractState' => '',
            'currency' => '',
            'capturedAmount' => '0.00',
            'refundedAmount' => '0.00',
            'captureBound' => 0.0,
            'captureBoundFormatted' => '0.00',
            'refundBound' => 0.0,
            'refundBoundFormatted' => '0.00',
            'isCapturable' => false,
            'isRefundable' => false,
            'isCancellable' => false,
            'dashboardUrl' => null,
            'transactions' => [],
            'errorMessage' => $message,
            'validationErrors' => $validationErrors,
        ];
    }

    private function money(?float $amount): string
    {
        return number_format($amount ?? 0.0, 2, '.', '');
    }

    /**
     * OXID's Order::getFieldData() is typed `mixed`; coerce only genuine scalars to string.
     */
    private function readOrderField(Order $order, string $field): string
    {
        $value = $order->getFieldData($field);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Reset any cached API data.
     *
     * Story 2 (Sprint 9): Added for API parity with Stripe. Mollie's TransactionHistoryService
     * reads directly from the Mollie API on each fetch() call, so there is no per-request
     * cache to bust. This no-op exists so both panel providers share the same interface
     * and the calling code (MolliePaymentPanelProvider) can call it uniformly after any
     * admin action without branching on the provider.
     *
     * @see StripePanelViewDataBuilder::resetViewCache()
     */
    public function resetViewCache(): void
    {
        // No-op: Mollie reads directly from API on each call, no stale cache.
    }
}
