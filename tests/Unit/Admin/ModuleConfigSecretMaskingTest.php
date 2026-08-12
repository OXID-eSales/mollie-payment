<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 10 Story 1 — drift guard for the masked module-config settings.
 *
 * The admin module-settings tab renders every `str` setting through OXID's stock
 * `module_config.html.twig`, i.e. as a clear-text `<input type=text>`. Mollie's override
 * re-renders the credential settings as `type="password"` behind an eye toggle.
 *
 * Threat model (deliberately narrow): the value is still written into the `value=""`
 * attribute and travels to the browser. This mitigates shoulder-surfing, screen sharing
 * and screenshots — it does NOT hide the key from devtools or view-source.
 *
 * This test asserts on the template **source**, not on rendered HTML: the Unit suite has no
 * Twig environment, and standing one up (stock parent template + `translate()` / `help_id()`
 * extensions) is disproportionate for a two-field change. Rendering is covered for real by
 * the Playwright spec `tests/e2e/playwright/tests/admin/mollie-api-key-mask.spec.ts`.
 * Precedent for pinning presentation shape from a unit test:
 * {@see \OxidEsales\Payments\Mollie\Tests\Unit\Security\FrontendConfidentialityParityTest}.
 */
#[Group('security')]
#[Group('admin')]
final class ModuleConfigSecretMaskingTest extends TestCase
{
    private const TEMPLATE_RELATIVE_PATH =
        'views/twig/extensions/themes/admin_twig/module_config.html.twig';

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function template(): string
    {
        $path = $this->moduleRoot() . '/' . self::TEMPLATE_RELATIVE_PATH;
        self::assertFileExists($path, 'the module-config override template is missing');

        return (string)file_get_contents($path);
    }

    /**
     * The names the template actually gates on, read out of its single Twig array literal
     * (`{% set mollie_secret_vars = [...] %}`). Keeping the list in one Twig `set` — rather
     * than repeating the markup per field — is what lets this test compare template and
     * PHP const as sets instead of grepping duplicated blocks.
     *
     * @return list<string>
     */
    private function maskedVarsDeclaredInTemplate(): array
    {
        $matched = preg_match(
            '/\{%\s*set\s+mollie_secret_vars\s*=\s*\[(?<list>[^\]]*)\]\s*%\}/',
            $this->template(),
            $matches,
        );
        self::assertSame(1, $matched, 'template must declare `{% set mollie_secret_vars = [...] %}`');

        preg_match_all('/\'(?<name>[^\']+)\'/', $matches['list'], $names);

        return $names['name'];
    }

    /**
     * @return list<string>
     */
    private function settingNamesFromMetadata(): array
    {
        $aModule = [];
        require $this->moduleRoot() . '/metadata.php';
        /** @var array{settings?: list<array{name?: string}>} $aModule */
        $settings = $aModule['settings'] ?? [];

        return array_values(array_filter(array_map(
            static fn (array $setting): string => (string)($setting['name'] ?? ''),
            $settings,
        )));
    }

    public function testSecretModuleSettingsListsExactlyTheTwoApiKeys(): void
    {
        // D1: the profile id (pfl_…) is public by design — it is shipped to the browser by the
        // OPC footer widget for `new Mollie(profileId, …)`. The webhook URL is public too, and
        // Mollie signs nothing, so there is no webhook secret to protect.
        self::assertSame(
            ['sMollieTestKey', 'sMollieLiveKey'],
            MollieDefinitions::SECRET_MODULE_SETTINGS,
        );
    }

    public function testTemplateMasksExactlyTheSettingsDeclaredSecret(): void
    {
        self::assertSame(
            MollieDefinitions::SECRET_MODULE_SETTINGS,
            $this->maskedVarsDeclaredInTemplate(),
            'template masked-var list drifted from MollieDefinitions::SECRET_MODULE_SETTINGS',
        );
    }

    public function testSecretSettingsRenderAsPasswordInputsCarryingTheMollieSecretMarker(): void
    {
        $template = $this->template();

        self::assertMatchesRegularExpression(
            '/<input\s+type="password"[^>]*name="confstrs\[\{\{\s*module_var\s*\}\}\]"/',
            $template,
            'the masked branch must render a type="password" input bound to confstrs[module_var]',
        );
        self::assertStringContainsString('data-mollie-secret', $template);
        self::assertStringContainsString(
            'autocomplete="off"',
            $template,
            'no browser password-manager prompt on the module form',
        );
    }

    public function testTemplateNeverRendersASecretSettingAsPlainText(): void
    {
        $template = $this->template();

        // The override handles *only* the secret settings; everything else falls through to
        // {{ parent() }}. So a `type="text"` input anywhere in this file can only be a secret
        // rendered in clear — which is exactly the regression this sprint removes.
        self::assertDoesNotMatchRegularExpression(
            '/<input[^>]*type=["\']?text\b/',
            $template,
            'a secret setting is rendered as clear text',
        );
    }

    public function testEachSecretInputIsFollowedByAnAccessibleToggleButton(): void
    {
        $template = $this->template();

        // Count the marker on <input> tags only — the inline JS mentions it too, as a selector.
        $inputs = preg_match_all('/<input[^>]*data-mollie-secret/', $template);
        $toggles = preg_match_all('/class="mollie-key-toggle"/', $template);
        self::assertGreaterThan(0, $inputs, 'no masked input found');
        self::assertSame($inputs, $toggles, 'every masked input needs exactly one toggle button');

        $matched = preg_match(
            '/<input[^>]*data-mollie-secret[^>]*>\s*<button(?<attrs>[^>]*)>/',
            $template,
            $matches,
        );
        self::assertSame(1, $matched, 'the toggle button must directly follow its masked input');

        $attributes = $matches['attrs'];
        self::assertStringContainsString('type="button"', $attributes, 'must not submit the form');
        self::assertStringContainsString('class="mollie-key-toggle"', $attributes);
        self::assertStringContainsString('aria-pressed="false"', $attributes, 'starts in the masked state');
        self::assertMatchesRegularExpression(
            '/aria-label="[^"]+"/',
            $attributes,
            'the toggle needs a non-empty accessible name',
        );
    }

    public function testTemplateDelegatesEveryNonSecretSettingToTheParentTemplate(): void
    {
        // D4: Stripe and PayPal ship their own override of this same template. Without
        // {{ parent() }} whichever module lands later in the Twig chain swallows the other's
        // fields and blanks out the form.
        self::assertStringContainsString('{{ parent() }}', $this->template());
    }

    public function testEverySecretSettingIsARealModuleSetting(): void
    {
        $settingNames = $this->settingNamesFromMetadata();

        foreach (MollieDefinitions::SECRET_MODULE_SETTINGS as $secret) {
            self::assertContains(
                $secret,
                $settingNames,
                "masked setting '$secret' does not exist in metadata.php",
            );
        }
    }

    public function testEverySettingDeclaredSecretIsActuallyMaskedByTheTemplate(): void
    {
        $masked = $this->maskedVarsDeclaredInTemplate();

        foreach ($this->settingNamesFromMetadata() as $name) {
            if (!in_array($name, MollieDefinitions::SECRET_MODULE_SETTINGS, true)) {
                continue;
            }
            self::assertContains($name, $masked, "setting '$name' is declared secret but not masked");
        }
    }
}
