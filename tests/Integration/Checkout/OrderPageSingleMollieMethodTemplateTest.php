<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Checkout;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class MollieOrderProbePayment
{
    public function getId(): string
    {
        return 'oe_payments_mollie';
    }

    public function isStripePaymentMethod(): bool
    {
        return false;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

class MollieOrderProbeView
{
    public function getPayment(): MollieOrderProbePayment
    {
        return new MollieOrderProbePayment();
    }

    public function isLowOrderPrice(): bool
    {
        return false;
    }

    public function isSingleShippingAutoAssigned(): bool
    {
        return true;
    }

    public function isSinglePaymentAutoAssigned(): bool
    {
        return true;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * Stands in for the shop's oViewConf global: a Twig context variable of the
 * same name shadows the global, so the inline-card decision and the method
 * list are under the test's control.
 */
class MollieOrderProbeViewConf
{
    /** @param list<array{id: string, name: string, image: string|null}> $methods */
    public function __construct(private readonly array $methods)
    {
    }

    public function getMollieModuleId(): string
    {
        return 'oe_payments_mollie';
    }

    public function isMollieInlineCardEnabled(): bool
    {
        return true;
    }

    /** @return list<array{id: string, name: string, image: string|null}> */
    public function getMollieMethods(): array
    {
        return $this->methods;
    }

    public function getMollieProfileId(): string
    {
        return 'pfl_probe';
    }

    public function isMollieTestMode(): bool
    {
        return true;
    }

    public function getMollieComponentsLocale(): string
    {
        return 'en_US';
    }

    public function isMollieDebugLoggingEnabled(): bool
    {
        return false;
    }

    public function getMollieJsPath(): string
    {
        return 'js/mollie-frontend.min.js';
    }

    public function getMollieModuleVersion(): string
    {
        return '0.0.0-test';
    }

    /** @param array<int, mixed> $args */
    public function getModuleUrl(string $moduleId, string $path = '', array ...$args): string
    {
        return 'https://shop.test/out/modules/' . $moduleId . '/' . $path;
    }

    public function getSslSelfLink(): string
    {
        return 'https://shop.test/index.php?';
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

class MollieOrderProbeBasket
{
    public function getProductsCount(): int
    {
        return 1;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

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
        self::assertStringContainsString('data-controller="mollie-components"', $output, 'the inline block must render');

        return $output;
    }
}
