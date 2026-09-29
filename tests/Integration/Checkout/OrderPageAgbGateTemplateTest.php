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

    /**
     * MOL-9: the gate sits on the inline block, not on the button alone - the method selector and the
     * card fields are its `region` target (locked with `inert` while the AGB box is unticked) and the
     * order button its `button` target.
     */
    public function testInlineComponentsBlockIsGatedByTheAgbCheckboxAsAWhole(): void
    {
        $output = $this->renderOrderPage(inlineCard: true);

        self::assertSame(
            1,
            preg_match('/<div[^>]*data-controller="[^"]*\bmollie-components\b[^"]*\bmollie-agb-gate\b[^"]*"/', $output),
            'the gate controller lives on the mollie-components wrapper'
        );
        self::assertSame(1, preg_match('/<div[^>]*data-mollie-agb-gate-target="region"[^>]*>/', $output, $region));
        $regionStart = strpos($output, $region[0]);
        self::assertNotFalse($regionStart);
        $regionEnd = strpos($output, 'data-mollie-components-target="error"', $regionStart);
        $regionMarkup = substr($output, $regionStart, (int) $regionEnd - $regionStart);
        self::assertStringContainsString('name="mollieMethod"', $regionMarkup, 'the method selector is inside the region');
        self::assertStringContainsString('data-mollie-components-target="fields"', $regionMarkup, 'the card fields are inside the region');

        $button = $this->orderButton($output, 'mollie-components#placeOrder');
        self::assertStringContainsString('data-mollie-agb-gate-target="button"', $button);
        self::assertDoesNotMatchRegularExpression('/data-controller="[^"]*\bmollie-agb-gate\b/', $button, 'one gate per block, on the wrapper');
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
