<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Widget;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OPC footer widget: one enabled Mollie method is not a choice (the rule the
 * standard order page follows since 2026-09-18). With exactly one method the
 * footer names it read-only and keeps a hidden, checked radio carrying the
 * `methodRadio` target, so the controller's `_selectedMethod()` still finds
 * it and the method still rides through processCheckout. With two or more
 * methods the selector renders as before.
 */
#[Group('integration')]
#[Group('requires-oxid-container')]
final class MollieFooterSingleMethodTemplateTest extends TestCase
{
    private const TEMPLATE = '@oe_payments_mollie/widget/checkout/mollie-footer.html.twig';

    private const CARD = ['id' => 'creditcard', 'name' => 'Card', 'image' => 'https://img.test/card.svg'];
    private const PAYPAL = ['id' => 'paypal', 'name' => 'PayPal', 'image' => null];

    public function testOneMethodIsNamedReadOnlyInsteadOfOfferedAsAChoice(): void
    {
        $output = $this->renderFooter([self::CARD]);

        self::assertStringNotContainsString('class="mollie-methods', $output, 'no selector for one method');
        self::assertStringContainsString('data-mollie-order-method="fixed"', $output);
        self::assertStringContainsString('Card', $output, 'the method is still named');
        self::assertSame(1, preg_match_all('/<input[^>]*name="mollieMethod"[^>]*>/', $output, $inputs));
        self::assertStringContainsString('value="creditcard"', $inputs[0][0]);
        self::assertStringContainsString('checked', $inputs[0][0]);
        self::assertStringContainsString('data-mollie-checkout-footer-target="methodRadio"', $inputs[0][0]);
    }

    public function testTwoMethodsStillRenderTheSelector(): void
    {
        $output = $this->renderFooter([self::CARD, self::PAYPAL]);

        self::assertStringContainsString('class="mollie-methods', $output);
        self::assertStringNotContainsString('data-mollie-order-method="fixed"', $output);
        self::assertSame(2, preg_match_all('/<input[^>]*name="mollieMethod"[^>]*>/', $output));
    }

    /** @param list<array{id: string, name: string, image: string|null}> $methods */
    private function renderFooter(array $methods): string
    {
        $renderer = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class)
            ->getTemplateRenderer();

        $output = $renderer->renderTemplate(self::TEMPLATE, [
            'checkoutData' => ['paymentMethodId' => 'oe_payments_mollie', 'csrfToken' => 'tok'],
            'mollieConfig' => [
                'methods' => $methods,
                'profileId' => 'pfl_probe',
                'testmode' => true,
                'locale' => 'en_US',
                'inlineCardEnabled' => true,
                'debug' => false,
            ],
        ]);

        // MOL-15: the root element carries the footer controller AND the user-data validator.
        self::assertMatchesRegularExpression(
            '/data-controller="[^"]*\bmollie-checkout-footer\b[^"]*"/',
            $output,
            'the widget must render'
        );

        return $output;
    }
}
