<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds as ProviderBounds;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBoundsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptionsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Mollie's panel for payment-base's shared "Payment" admin tab.
 *
 * Thin shim: view data comes from {@see MolliePanelViewDataBuilder}; the Mollie-only release runs through
 * {@see ReleaseActionInterface}. Capture, refund and cancel are not handled here (VR-22 A6): payment-base's
 * MoneyOperationRunner owns them for the classic admin and the admin API, calling Mollie's gateways. The declared
 * bounds are a read model only; they limit nothing.
 */
final class MolliePaymentPanelProvider implements
    PaymentPanelProviderInterface,
    AdminActionOptionsProviderInterface,
    AdminActionBoundsProviderInterface
{
    public const PROVIDER_KEY = MollieDefinitions::PROVIDER_NAME;

    private const ACTION_RELEASE = 'release';
    private const PANEL_TEMPLATE = '@oe_payments_mollie/admin/panel/mollie_panel.html.twig';

    public function __construct(
        private readonly MolliePanelViewDataBuilder $viewDataBuilder,
        private readonly MolliePanelOrderLoader $orderLoader,
        private readonly AdminActionBoundsInterface $bounds,
        private readonly ReleaseActionInterface $releaseAction,
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
        if ($action !== self::ACTION_RELEASE) {
            return;
        }

        $reason = $request['release_reason'] ?? null;
        $this->releaseAction->run($context->orderId, is_string($reason) && $reason !== '' ? $reason : null);
        // The admin API answers right after the action with the payment as it stands: no pre-action memo.
        $this->viewDataBuilder->resetViewCache();
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
}
