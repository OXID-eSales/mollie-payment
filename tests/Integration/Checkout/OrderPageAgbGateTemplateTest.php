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

/**
 * MOL-11: both Mollie "Order now" buttons on the standard order step carry the
 * `mollie-agb-gate` Stimulus controller, which keeps the button inactive until
 * every agreement checkbox Apex renders is ticked. The classic redirect button
 * keeps its MOL-18 single-submit controller next to it.
 */
#[Group('integration')]
#[Group('requires-oxid-container')]
final class OrderPageAgbGateTemplateTest extends TestCase
{
    private const TEMPLATE = 'page/checkout/order.html.twig';

    private const CARD = ['id' => 'creditcard', 'name' => 'Card', 'image' => null];
    private const IDEAL = ['id' => 'ideal', 'name' => 'iDEAL', 'image' => null];

    public function testInlineComponentsOrderButtonIsGatedByTheAgbCheckbox(): void
    {
        $output = $this->renderOrderPage(inlineCard: true);

        $button = $this->orderButton($output, 'mollie-components#placeOrder');
        self::assertMatchesRegularExpression('/data-controller="[^"]*\bmollie-agb-gate\b[^"]*"/', $button);
    }

    public function testClassicRedirectOrderButtonIsGatedByTheAgbCheckbox(): void
    {
        $output = $this->renderOrderPage(inlineCard: false);

        $button = $this->orderButton($output, 'mollie-place-order#submit');
        self::assertMatchesRegularExpression('/data-controller="[^"]*\bmollie-place-order\b[^"]*"/', $button);
        self::assertMatchesRegularExpression('/data-controller="[^"]*\bmollie-agb-gate\b[^"]*"/', $button);
    }

    private function orderButton(string $output, string $action): string
    {
        $found = preg_match('/<button[^>]*data-action="[^"]*' . preg_quote($action, '/') . '[^"]*"[^>]*>/s', $output, $m);
        self::assertSame(1, $found, 'the order button wired to ' . $action . ' must render');

        return $m[0];
    }

    private function renderOrderPage(bool $inlineCard): string
    {
        $renderer = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class)
            ->getTemplateRenderer();

        $output = $renderer->renderTemplate(self::TEMPLATE, [
            'oView' => new MollieOrderProbeView(),
            'oViewConf' => new MollieOrderProbeViewConf([self::CARD, self::IDEAL], $inlineCard),
            'oxcmp_basket' => new MollieOrderProbeBasket(),
        ]);

        self::assertNotSame(
            self::TEMPLATE,
            trim($output),
            'the shop renderer returned the template name — no frontend theme in this environment'
        );

        return $output;
    }
}
