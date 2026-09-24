<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Component\Widget;

use OxidEsales\Payments\Mollie\Component\Widget\MollieCheckoutFooter;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\AllowedSymbolsDescriber;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\ValidationRulesProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Mollie OPC footer widget.
 *
 * Testable-subclass pattern (mirrors StripeCheckoutFooterTest): the OXID framework seams
 * (parent::render, addTplParam, getViewParameter) and the ViewConfig-backed getMollieConfig() are
 * overridden so no shop bootstrap is required.
 */
#[CoversClass(MollieCheckoutFooter::class)]
final class MollieCheckoutFooterTest extends TestCase
{
    public function testRenderReturnsTheWidgetTemplatePath(): void
    {
        $template = $this->buildFooter()->render();

        self::assertStringContainsString(MollieDefinitions::MODULE_ID, $template);
        self::assertStringContainsString('mollie-footer', $template);
    }

    public function testRenderSetsCheckoutDataAndMollieConfigTplParams(): void
    {
        $footer = $this->buildFooter(['paymentMethodId' => 'oe_payments_mollie', 'csrfToken' => 'tok']);

        $footer->render();
        $params = $footer->getCapturedTplParams();

        self::assertArrayHasKey('checkoutData', $params);
        self::assertArrayHasKey('mollieConfig', $params);
    }

    public function testGetCheckoutDataReadsPaymentIdAndCsrfFromViewParameters(): void
    {
        $footer = $this->buildFooter(['paymentMethodId' => 'oe_payments_mollie', 'csrfToken' => 'csrf_abc']);

        $data = $footer->exposedGetCheckoutData();

        self::assertSame('oe_payments_mollie', $data['paymentMethodId']);
        self::assertSame('csrf_abc', $data['csrfToken']);
    }

    public function testGetCheckoutDataDefaultsMissingViewParametersToEmptyStrings(): void
    {
        $data = $this->buildFooter([])->exposedGetCheckoutData();

        self::assertSame('', $data['paymentMethodId']);
        self::assertSame('', $data['csrfToken']);
    }

    /**
     * @param array<string, mixed> $viewParams
     */
    // MOL-15: the footer hands the validator controller everything it needs to call the central
    // payment-base endpoint with Mollie's rules.
    public function testGetCheckoutDataExposesValidationUrlPluginIdAndAllowedSymbols(): void
    {
        $data = $this->buildFooter(['csrfToken' => 'tok'])->exposedGetCheckoutData();

        self::assertSame('https://shop.test/index.php?cl=oepaymentvalidationapi&fnc=validate', $data['validationUrl']);
        self::assertSame(MollieDefinitions::MODULE_ID, $data['pluginModuleId']);
        self::assertSame("letters, digits, spaces, ' - . , /", $data['fieldAllowed']['street']);
        self::assertArrayHasKey('postalCode', $data['fieldAllowed']);
    }

    private function buildFooter(array $viewParams = []): TestableMollieCheckoutFooter
    {
        return new TestableMollieCheckoutFooter($viewParams);
    }
}

// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
final class TestableMollieCheckoutFooter extends MollieCheckoutFooter
{
    /** @var array<string, mixed> */
    private array $tplParams = [];

    /**
     * @param array<string, mixed> $viewParams
     * @param array<string, mixed> $stubMollieConfig
     */
    public function __construct(
        private readonly array $viewParams = [],
        private readonly array $stubMollieConfig = ['methods' => [], 'profileId' => '', 'testmode' => true],
    ) {
        // Skip the OXID WidgetController constructor (needs a shop bootstrap).
    }

    public function render(): string
    {
        // Skip parent::render() (boots the OXID view engine).
        $this->addTplParam('checkoutData', $this->getCheckoutData());
        $this->addTplParam('mollieConfig', $this->getMollieConfig());

        return $this->_sThisTemplate;
    }

    public function addTplParam($name, $value): void
    {
        $this->tplParams[$name] = $value;
    }

    /** @return array<string, mixed> */
    public function getCapturedTplParams(): array
    {
        return $this->tplParams;
    }

    protected function getShopUrl(): string
    {
        return 'https://shop.test/';
    }

    protected function validationRulesProvider(): ValidationRulesProvider
    {
        return new ValidationRulesProvider();
    }

    protected function allowedSymbolsDescriber(): AllowedSymbolsDescriber
    {
        $translator = new class implements LanguageTranslatorInterface {
            public function translateString(string $key): string
            {
                return match ($key) {
                    'MOLLIE_VALIDATION_CLASS_LETTERS' => 'letters',
                    'MOLLIE_VALIDATION_CLASS_DIGITS' => 'digits',
                    'MOLLIE_VALIDATION_CLASS_SPACES' => 'spaces',
                    default => $key,
                };
            }
        };

        return (new ValidationRulesProvider())->createDescriber($translator);
    }

    public function getViewParameter($name): mixed
    {
        return $this->viewParams[$name] ?? null;
    }

    /**
     * Stub the ViewConfig-backed config so the test needs no shop bootstrap
     * (the Mollie ViewConfig extension cannot be instantiated in unit context).
     *
     * @return array<string, mixed>
     */
    protected function getMollieConfig(): array
    {
        return $this->stubMollieConfig;
    }

    /** @return array<string, mixed> */
    public function exposedGetCheckoutData(): array
    {
        return $this->getCheckoutData();
    }
}
