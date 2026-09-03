<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Admin;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 136 — the "payment method used" row must actually reach the page.
 *
 * The unit tests pin the view-data MolliePanelViewDataBuilder produces; this
 * pins the other half, which no unit test can see: that the template consumes
 * that shape without a Twig error and renders each of the three states an
 * operator will meet. A view-data key renamed on one side of that boundary
 * fails here.
 *
 * No skip-on-unavailable-container guard: the Integration suite runs against a
 * booted shop with the module installed, and a container that cannot boot is a
 * result worth failing on, not one worth hiding behind a green skip.
 */
#[Group('integration')]
#[Group('sprint-136')]
final class PaymentMethodRowRenderTest extends TestCase
{
    private const TEMPLATE = '@oe_payments_mollie/admin/panel/mollie_panel.html.twig';

    public function testWalletPaymentRendersTheWalletAndTheDemotedCard(): void
    {
        $cell = $this->renderRow([
            'isKnown' => true,
            'label' => 'Apple Pay',
            'detail' => 'Mastercard •••• 0007',
            'raw' => 'creditcard',
        ]);

        self::assertStringContainsString('Apple Pay', $cell);
        self::assertStringContainsString('Mastercard', $cell);
        self::assertStringContainsString('0007', $cell);
    }

    public function testMethodWithoutCardDetailRendersTheLabelAlone(): void
    {
        $cell = $this->renderRow([
            'isKnown' => true,
            'label' => 'Klarna',
            'detail' => null,
            'raw' => 'klarnapaylater',
        ]);

        self::assertStringContainsString('Klarna', $cell);
        self::assertStringNotContainsString('••••', $cell);
    }

    public function testUnknownMethodRendersADash(): void
    {
        $cell = $this->renderRow([
            'isKnown' => false,
            'label' => '',
            'detail' => null,
            'raw' => null,
        ]);

        self::assertStringContainsString('mdash', $cell);
    }

    /**
     * @param array{isKnown: bool, label: string, detail: ?string, raw: ?string} $paymentMethod
     */
    private function renderRow(array $paymentMethod): string
    {
        $html = $this->render($paymentMethod);

        self::assertStringContainsString(
            'data-testid="payment-method-used"',
            $html,
            'The payment-method row did not render at all.'
        );

        preg_match('#data-testid="payment-method-used"[^>]*>(.*?)</td>#s', $html, $matches);

        return $matches[1] ?? '';
    }

    /**
     * @param array{isKnown: bool, label: string, detail: ?string, raw: ?string} $paymentMethod
     */
    private function render(array $paymentMethod): string
    {
        $renderer = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class)
            ->getTemplateRenderer();

        return $renderer->renderTemplate(self::TEMPLATE, $this->viewData() + ['paymentMethod' => $paymentMethod]);
    }

    /**
     * The rest of the panel's view-data, in the shape
     * {@see \OxidEsales\Payments\Mollie\Admin\MolliePanelViewDataBuilder} emits.
     *
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        return [
            'orderId' => 'oid1',
            'orderNumber' => '4711',
            'paymentType' => 'mollie_payment',
            'contractId' => 'c1',
            'providerOrderId' => 'tr_1',
            'contractState' => 'fulfilled',
            'currency' => 'EUR',
            'capturedAmount' => '100.00',
            'refundedAmount' => '0.00',
            'captureBound' => 0.0,
            'captureBoundFormatted' => '0.00',
            'refundBound' => 100.0,
            'refundBoundFormatted' => '100.00',
            'isCapturable' => false,
            'isRefundable' => true,
            'isCancellable' => false,
            'dashboardUrl' => null,
            'transactions' => [],
            'errorMessage' => null,
            'validationErrors' => [],
        ];
    }
}
