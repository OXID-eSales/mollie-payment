<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionDispatcherInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Mollie's panel for payment-base's shared "Payment" admin tab.
 *
 * Thin shim: delegates view-data assembly to {@see MolliePanelViewDataBuilder} and dispatches
 * capture / refund / cancel actions through the existing Sprint 6
 * {@see \OxidEsales\Payments\Mollie\Controller\Admin\OrderActionDispatcher} (wired here via the
 * shared {@see AdminActionDispatcherInterface}, but bound to the concrete Mollie class in
 * services.yaml — NOT a global interface alias, since Stripe/PayPal also implement it). Amount
 * inputs are gated through {@see AdminAmountValidator} before ever reaching the dispatcher
 * (Story 3): an invalid amount never triggers a Mollie API call.
 */
final class MolliePaymentPanelProvider implements PaymentPanelProviderInterface
{
    public const PROVIDER_KEY = MollieDefinitions::PROVIDER_NAME;

    private const PANEL_TEMPLATE = '@oe_payments_mollie/admin/panel/mollie_panel.html.twig';

    public function __construct(
        private readonly AdminActionDispatcherInterface $actionDispatcher,
        private readonly MolliePanelViewDataBuilder $viewDataBuilder,
        private readonly MolliePanelOrderLoader $orderLoader,
        private readonly ContractRepositoryInterface $contracts,
        private readonly AdminActionBoundsInterface $bounds,
        private readonly AdminAmountValidator $amountValidator,
        private readonly AdminValidationFeedbackInterface $validationFeedback,
    ) {
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_KEY;
    }

    public function supports(PaymentPanelContext $context): bool
    {
        if (MollieDefinitions::isMolliePayment($context->paymentType)) {
            return true;
        }

        return $context->getProviderName() === self::PROVIDER_KEY;
    }

    public function build(PaymentPanelContext $context): PaymentPanelRenderable
    {
        $order = $this->orderLoader->loadById($context->orderId);
        $viewData = $order !== null ? $this->viewDataBuilder->build($order) : [];

        return new PaymentPanelRenderable(self::PANEL_TEMPLATE, $viewData, self::PROVIDER_KEY);
    }

    public function handleAction(string $action, array $request, PaymentPanelContext $context): void
    {
        $order = $this->orderLoader->loadById($context->orderId);
        if ($order === null) {
            return;
        }

        match ($action) {
            'refund' => $this->handleRefund($order, $request),
            'capture' => $this->handleCapture($order, $request),
            'cancel' => $this->handleCancel($order, $request),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleRefund(Order $order, array $request): void
    {
        $amountResult = $this->validateAmount($order, 'refund', $request['refund_amount'] ?? null);
        if (!$amountResult->isOk()) {
            $this->reject($order, 'refund_amount', $amountResult);
            return;
        }

        $this->actionDispatcher->refund(
            $order,
            $amountResult->amount,
            $this->parseString($request['refund_reason'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleCapture(Order $order, array $request): void
    {
        $amountResult = $this->validateAmount($order, 'capture', $request['capture_amount'] ?? null);
        if (!$amountResult->isOk()) {
            $this->reject($order, 'capture_amount', $amountResult);
            return;
        }

        $this->actionDispatcher->capture(
            $order,
            $amountResult->amount,
            $this->parseString($request['capture_reason'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleCancel(Order $order, array $request): void
    {
        $this->actionDispatcher->cancel($order, $this->parseString($request['cancel_reason'] ?? null));
    }

    /**
     * Absent input short-circuits to ok(null) = full action without ever looking up the
     * contract/bound — only a PRESENT amount needs to be checked against the live Mollie payment.
     */
    private function validateAmount(Order $order, string $action, mixed $raw): AmountValidationResult
    {
        if ($raw === null || $raw === '') {
            return AmountValidationResult::ok(null);
        }

        $contract = $this->contracts->findByOrderId((string) $order->getId());
        if ($contract === null) {
            return AmountValidationResult::failure(AmountValidationResult::CODE_EXCEEDS_BOUND);
        }

        $bound = $action === 'capture' ? $this->bounds->captureBound($contract) : $this->bounds->refundBound($contract);

        return $this->amountValidator->validate($raw, $bound);
    }

    private function reject(Order $order, string $field, AmountValidationResult $result): void
    {
        $this->validationFeedback->reject((string) $order->getId(), $field, (string) $result->code);
    }

    private function parseString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
