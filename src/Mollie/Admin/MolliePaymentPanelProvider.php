<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds as ProviderBounds;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBoundsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionDispatcherInterface;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptionsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Controller\Admin\MollieExtraActionDispatcherInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\UserDataValidatorInterface;

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
final class MolliePaymentPanelProvider implements
    PaymentPanelProviderInterface,
    AdminActionOptionsProviderInterface,
    AdminActionBoundsProviderInterface
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
        private readonly UserDataValidatorInterface $userDataValidator,
        // PAY-5: how this panel says no - the classic tab's feedback and the admin API's typed refusal in one.
        private readonly AdminActionRefusals $refusals,
        private readonly ReleaseRule $releaseRule,
        // MOL-32 S6: Mollie-only release action (optional so older wiring keeps working).
        private readonly ?MollieExtraActionDispatcherInterface $extraActions = null,
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
            'release' => $this->handleRelease($order, $request),
            default => null,
        };
    }

    /**
     * MOL-32 S6: release the remaining authorized amount after one or more partial captures. No
     * amount - Mollie releases the whole remainder; the reason is validated like the other free text.
     * PAY-5: the same {@see ReleaseRule} that decides whether the classic tab offers the button decides whether the
     * action runs (without a capture, "release" would cancel the whole hold - that is Cancel's business).
     *
     * @param array<string, mixed> $request
     */
    private function handleRelease(Order $order, array $request): void
    {
        if ($this->extraActions === null) {
            return;
        }

        $orderId = (string) $order->getId();
        $contract = $this->contracts->findByOrderId($orderId);
        if ($contract === null || !$this->releaseRule->isReleasable($contract)) {
            throw $this->refusals->notReleasable();
        }
        $reason = $this->parseString($request['release_reason'] ?? null);
        $this->assertTextAccepted($orderId, 'captureReason', $reason);

        $released = $this->extraActions->release($order, $reason);
        // The admin API answers right after the action with the payment as it stands: no pre-action memo.
        $this->viewDataBuilder->resetViewCache();
        if (!$released) {
            throw $this->refusals->releaseFailed();
        }
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleRefund(Order $order, array $request): void
    {
        $amountResult = $this->validateAmount($order, 'refund', $request['refund_amount'] ?? null);
        if (!$amountResult->isOk()) {
            throw $this->refusals->amount((string) $order->getId(), 'refund_amount', (string) $amountResult->code);
        }

        // Story 3 (Sprint 9): Optional admin description for audit trail.
        $description = $this->parseString($request['refund_description'] ?? null);
        $this->assertTextAccepted((string) $order->getId(), 'refundDescription', $description);
        $extras = $description !== null ? ['description' => $description] : [];

        $this->actionDispatcher->refund(
            $order,
            $amountResult->amount,
            $this->parseString($request['refund_reason'] ?? null),
            $extras,
        );

        // Story 2 (Sprint 9): Bust the per-request cache after refund so the same-request
        // re-render reads fresh post-refund data from Mollie (no-op but called for parity).
        $this->viewDataBuilder->resetViewCache();
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleCapture(Order $order, array $request): void
    {
        $amountResult = $this->validateAmount($order, 'capture', $request['capture_amount'] ?? null);
        if (!$amountResult->isOk()) {
            throw $this->refusals->amount((string) $order->getId(), 'capture_amount', (string) $amountResult->code);
        }

        $reason = $this->parseString($request['capture_reason'] ?? null);
        $this->assertTextAccepted((string) $order->getId(), 'captureReason', $reason);
        $this->actionDispatcher->capture($order, $amountResult->amount, $reason);

        // Story 2 (Sprint 9): Bust the per-request cache after capture.
        $this->viewDataBuilder->resetViewCache();
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleCancel(Order $order, array $request): void
    {
        $this->actionDispatcher->cancel($order, $this->parseString($request['cancel_reason'] ?? null));

        // Story 2 (Sprint 9): Bust the per-request cache after cancel.
        $this->viewDataBuilder->resetViewCache();
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

    /**
     * MOL-15: admin free text goes to Mollie with the capture / refund, so it runs through the same
     * character-level rules as the shopper's address (rules file entries `captureReason` and
     * `refundDescription`, address kind `admin`). Refused text is reported like a refused amount.
     *
     * @throws AdminActionRefusedException
     */
    private function assertTextAccepted(string $orderId, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        $failures = $this->userDataValidator->validateFieldMap([$field => $value], FieldValidationFailure::KIND_ADMIN);
        if ($failures !== []) {
            throw $this->refusals->text($orderId, array_values($failures));
        }
    }

    /**
     * PAY-5: what the Payment tab offers, so the admin API offers (and enforces) the same: the refund and cancel
     * reasons of the panel's selects, a refund description, and the 140 characters of its inputs.
     */
    public function actionOptions(PaymentContractInterface $contract): AdminActionOptions
    {
        return new AdminActionOptions(
            MollieDefinitions::ADMIN_REFUND_REASONS,
            MollieDefinitions::ADMIN_VOID_REASONS,
            true,
            MollieDefinitions::ADMIN_TEXT_MAX_LENGTH,
        );
    }

    public function boundsFor(PaymentContractInterface $contract): ?ProviderBounds
    {
        return $this->bounds->boundsFor($contract);
    }

    private function parseString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
