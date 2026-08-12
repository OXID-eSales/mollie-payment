<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Module;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use Twig\Environment;

/**
 * Sprint 10 Story 4 — Mollie's `module_config.html.twig` override must coexist with the other
 * modules that override the same admin template.
 *
 * This is the real risk in the sprint and it is integration-level: several modules each extend
 * the stock `module_config.html.twig` and Twig chains them. If Mollie's
 * `admin_module_config_var` block did not delegate every non-Mollie setting to
 * `{{ parent() }}`, whichever module lands earlier in the chain would swallow the ones behind it
 * and blank out their settings forms.
 *
 * The test drives the block through the **real, resolved chain** — no fixtures, no stub
 * templates — and asserts three things: Mollie's own secrets mask, a non-secret Mollie setting
 * falls through to the stock widget, and another module's secret still gets that module's
 * masking. It renders only the settings-row block, not the whole page, so it needs no admin
 * navigation/header context.
 *
 * @group integration
 * @group module
 * @group security
 */
#[Group('integration')]
#[Group('module')]
#[Group('security')]
final class ModuleConfigTemplateChainTest extends TestCase
{
    private const TEMPLATE = 'module_config.html.twig';
    private const BLOCK = 'admin_module_config_var';

    private Environment $twig;
    private string $lastChild;

    protected function setUp(): void
    {
        parent::setUp();

        // The template chain differs between the shop and admin themes.
        Registry::getConfig()->setAdminMode(true);

        $engine = ContainerFactory::getInstance()
            ->getContainer()
            ->get(TemplateRendererBridgeInterface::class)
            ->getTemplateRenderer()
            ->getTemplateEngine();

        // TwigEngine keeps both collaborators private and the container exposes neither as a
        // public service, so reflection is the only way in from a test. Worth it: anything
        // less means asserting against a chain we assembled ourselves rather than the shop's.
        $reflection = new ReflectionObject($engine);
        $this->twig = $this->readProperty($reflection, $engine, 'engine');
        $resolver = $this->readProperty($reflection, $engine, 'templateChainResolver');

        $this->lastChild = $resolver->getLastChild(self::TEMPLATE);
    }

    private function readProperty(ReflectionObject $reflection, object $object, string $name): mixed
    {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    /**
     * Renders one settings row through the resolved chain, as the module-config page would.
     */
    private function renderSettingRow(string $editObjectId, string $moduleVar): string
    {
        return $this->twig->load($this->lastChild)->renderBlock(self::BLOCK, [
            'oView' => new class ($editObjectId) {
                public function __construct(private string $editObjectId)
                {
                }

                public function getEditObjectId(): string
                {
                    return $this->editObjectId;
                }
            },
            'module_var' => $moduleVar,
            'var_type' => 'str',
            'confstrs' => [$moduleVar => 'test_TemplateChainProbe'],
            'readonly' => '',
        ]);
    }

    public function testMollieOverrideIsPartOfTheResolvedAdminTemplateChain(): void
    {
        $chain = $this->lastChild;
        // getLastChild() returns the most-derived override; Mollie is somewhere in the chain
        // behind it. Rendering below proves the position; here we only pin that a module (not
        // the stock template) won the resolution, i.e. the chain was built at all.
        self::assertStringStartsWith('@', $chain, 'no module override resolved for ' . self::TEMPLATE);
    }

    public function testEverySecretSettingRendersMaskedOnTheMollieTab(): void
    {
        foreach (MollieDefinitions::SECRET_MODULE_SETTINGS as $secret) {
            $html = $this->renderSettingRow(MollieDefinitions::MODULE_ID, $secret);

            self::assertStringContainsString('type="password"', $html, "$secret must be masked");
            self::assertStringContainsString('data-mollie-secret', $html, "$secret marker missing");
            self::assertStringContainsString('mollie-key-toggle', $html, "$secret toggle missing");
            self::assertStringContainsString("confstrs[$secret]", $html);
            self::assertStringNotContainsString('type=text', $html, "$secret leaked as clear text");
        }
    }

    public function testNonSecretMollieSettingFallsThroughToTheStockWidget(): void
    {
        // Proves {{ parent() }} delegation actually reaches the stock template through every
        // module override sitting between Mollie and it.
        $html = $this->renderSettingRow(MollieDefinitions::MODULE_ID, 'sMollieWebhookUrl');

        self::assertStringContainsString('confstrs[sMollieWebhookUrl]', $html);
        self::assertStringNotContainsString('data-mollie-secret', $html);
        self::assertStringNotContainsString('mollie-key-toggle', $html);
    }

    public function testMollieDoesNotMaskItsSettingsOnAnotherModulesTab(): void
    {
        // D2's gate: `getEditObjectId()`. On PayPal's settings tab nothing Mollie-specific
        // may appear, even for a var whose name Mollie recognises.
        $html = $this->renderSettingRow('oe_payments_paypal', 'sMollieTestKey');

        self::assertStringNotContainsString('data-mollie-secret', $html);
        self::assertStringNotContainsString('mollie-key-toggle', $html);
    }

    public function testAnotherModulesSecretIsStillMaskedByThatModule(): void
    {
        // The regression this whole story guards against: Mollie's block swallowing a sibling
        // module's field. Stripe ships its own masking for this setting — it must survive.
        $html = $this->renderSettingRow('oe_payments_stripe_wallet', 'sStripeWebhookEndpointSecret');

        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('data-stripe-secret', $html);
        self::assertStringNotContainsString('data-mollie-secret', $html);
    }
}
