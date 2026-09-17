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
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
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
        // Sprint 136: shared with AdminActionBounds, so the whole render is one
        // live Mollie read rather than one per question asked.
        private readonly MolliePaymentSnapshotProviderInterface $snapshots,
        private readonly LanguageTranslatorInterface $translator,
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
        // Gate capture/cancel on the LIVE Mollie payment status, not contract state: a
        // manual-capture order is committed by the shared checkout-return chain (STRP-118
        // pattern), so contract.isAuthorized() is never true after checkout even while the
        // Mollie payment is still an uncaptured `authorized` hold. Mirrors Stripe's panel.
        $authorizedHold = $this->bounds->isAuthorizedHold($contract);

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
            'isCapturable' => $authorizedHold && $captureBound > 0.0,
            'isRefundable' => $contract->getState()->isFulfilled() && $refundBound > 0.0,
            'isCancellable' => $authorizedHold,
            'dashboardUrl' => $this->dashboardUrl($providerOrderId),
            'transactions' => $this->transactionHistory->fetch($contract),
            'errorMessage' => null,
            'validationErrors' => $validationErrors,
            // Sprint 136: what the customer actually paid with. 'paymentType'
            // above is the shop's method id and reads 'mollie_payment' for every
            // Mollie order.
            'paymentMethod' => $this->buildPaymentMethod($this->snapshots->snapshot($contract)),
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
            // No contract means no Mollie payment to read — but the template
            // reads this key unconditionally, so the shape must still be there.
            'paymentMethod' => $this->buildPaymentMethod(null),
        ];
    }

    /**
     * Project the live payment's method facts onto the four values the panel row
     * needs. An unknown method is a first-class outcome (no payment yet, or the
     * API read failed) and is signalled by `isKnown: false`, which the template
     * renders as an em dash.
     *
     * @return array{isKnown: bool, label: string, detail: ?string, raw: ?string}
     */
    private function buildPaymentMethod(?MolliePaymentDto $payment): array
    {
        $descriptor = PaymentMethodDescriptor::fromPayment($payment);

        return [
            'isKnown' => $descriptor->isKnown(),
            'label' => $this->paymentMethodLabel($descriptor),
            'detail' => $descriptor->detail(),
            'raw' => $descriptor->rawType,
        ];
    }

    /**
     * Translated method name, falling back to the raw Mollie code whenever there
     * is no key for it or the key has no translation — OXID returns the ident
     * itself in that case, and "MOLLIE_PAYMENT_METHOD_IDEAL" must never reach an
     * operator.
     */
    private function paymentMethodLabel(PaymentMethodDescriptor $descriptor): string
    {
        $fallback = (string) $descriptor->displayType();
        $key = $descriptor->labelKey();

        if ($key === null) {
            return $fallback;
        }

        $translated = $this->translator->translateString($key);

        if ($translated === '' || $translated === $key) {
            return $fallback;
        }

        return $translated;
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
     * Drop every per-request memo so the next {@see build()} reads Mollie again.
     *
     * Story 2 (Sprint 9) added this for API parity with Stripe, as a no-op: at the time nothing
     * in the Mollie render was cached. Sprint 136 then made the live payment a per-request memo
     * ({@see MolliePaymentSnapshotProviderInterface}), and the no-op silently became a bug: the
     * action request validated the amount against the memoized payment, acted, and re-rendered
     * the same memo — the panel showed pre-action bounds until the operator reloaded (2026-09-17).
     * {@see MolliePaymentPanelProvider} calls this after every successful action.
     *
     * @see StripePanelViewDataBuilder::resetViewCache()
     */
    public function resetViewCache(): void
    {
        $this->snapshots->reset();
    }
}
