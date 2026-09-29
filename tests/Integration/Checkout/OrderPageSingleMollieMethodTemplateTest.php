<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Checkout;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use OxidEsales\Payments\Mollie\Tests\Integration\Checkout\Probe\MollieOrderProbeBasket;
use OxidEsales\Payments\Mollie\Tests\Integration\Checkout\Probe\MollieOrderProbeView;
use OxidEsales\Payments\Mollie\Tests\Integration\Checkout\Probe\MollieOrderProbeViewConf;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Probe/probes.php';

/**
 * One enabled Mollie method is not a choice. The inline order block used to
 * open with "Choose your payment method" and a single radio; with exactly one
 * method it now names the method read-only and keeps the (hidden, checked)
 * form field so the order still submits with it and the card flow still
 * detects "creditcard". With two or more methods the selector renders as
 * before. Same rule payment-base applies to the payment and shipping cards.
 */
#[Group('integration')]
#[Group('requires-oxid-container')]
final class OrderPageSingleMollieMethodTemplateTest extends TestCase
{
    private const TEMPLATE = 'page/checkout/order.html.twig';

    private const CARD = ['id' => 'creditcard', 'name' => 'Card', 'image' => 'https://img.test/card.svg'];
    private const IDEAL = ['id' => 'ideal', 'name' => 'iDEAL', 'image' => null];

    public function testOneMethodIsNamedReadOnlyInsteadOfOfferedAsAChoice(): void
    {
        $output = $this->renderOrderPage([self::CARD]);

        self::assertStringNotContainsString('class="mollie-methods', $output, 'no selector for one method');
        self::assertStringContainsString('data-mollie-order-method="fixed"', $output);
        self::assertStringContainsString('Card', $output, 'the method is still named');
        self::assertSame(1, preg_match_all('/<input[^>]*name="mollieMethod"[^>]*>/', $output, $inputs));
        self::assertStringContainsString('value="creditcard"', $inputs[0][0]);
        self::assertStringContainsString('checked', $inputs[0][0], 'the value still submits with the order form');
        self::assertStringContainsString('form="orderConfirmAgbBottom"', $inputs[0][0]);
    }

    public function testTwoMethodsStillRenderTheSelector(): void
    {
        $output = $this->renderOrderPage([self::CARD, self::IDEAL]);

        self::assertStringContainsString('class="mollie-methods', $output);
        self::assertStringNotContainsString('data-mollie-order-method="fixed"', $output);
        self::assertSame(2, preg_match_all('/<input[^>]*name="mollieMethod"[^>]*>/', $output));
    }

    /** @param list<array{id: string, name: string, image: string|null}> $methods */
    private function renderOrderPage(array $methods): string
    {
        $renderer = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class)
            ->getTemplateRenderer();

        $output = $renderer->renderTemplate(self::TEMPLATE, [
            'oView' => new MollieOrderProbeView(),
            'oViewConf' => new MollieOrderProbeViewConf($methods),
            'oxcmp_basket' => new MollieOrderProbeBasket(),
        ]);

        self::assertNotSame(
            self::TEMPLATE,
            trim($output),
            'the shop renderer returned the template name — no frontend theme in this environment'
        );
        // MOL-9: the wrapper also carries the AGB gate controller.
        self::assertMatchesRegularExpression('/data-controller="[^"]*\bmollie-components\b[^"]*"/', $output, 'the inline block must render');

        return $output;
    }
}
