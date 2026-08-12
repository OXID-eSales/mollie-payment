<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 10 Story 1 — drift guard between the secret-setting list and the admin template that masks
 * it.
 *
 * The module's API keys used to render through OXID's stock `module_config.html.twig` as plain
 * `<input type="text">`, so both were readable on screen on the Settings tab. The override added in
 * Story 2 masks them — but a mask is only worth anything if it keeps covering every secret, and the
 * failure mode of forgetting one is silent: the new field simply renders in clear text next to the
 * masked ones and looks unremarkable.
 *
 * So the list lives in exactly one place ({@see MollieDefinitions::SECRET_MODULE_SETTINGS}) and this
 * test reconciles it against both `metadata.php` and the template source in three directions:
 *
 *  - every declared secret is actually masked in the template;
 *  - no declared secret is rendered as `type="text"` anywhere;
 *  - every declared secret is a real setting, and no real setting that *should* be secret is missing
 *    from the list.
 *
 * Asserting on the template **source** rather than rendered HTML is deliberate: the Unit suite has no
 * Twig environment, and standing one up (stock parent template + `translate()` + `help_id()`
 * extensions) is disproportionate for a two-field change. Rendering is covered for real by
 * `tests/e2e/playwright/tests/admin/mollie-api-key-mask.spec.ts`.
 */
#[CoversClass(MollieDefinitions::class)]
#[Group('security')]
final class ModuleConfigSecretMaskingTest extends TestCase
{
    private const TEMPLATE = 'views/twig/extensions/themes/admin_twig/module_config.html.twig';

    public function testSecretSettingsListIsTheExpectedTwoKeys(): void
    {
        self::assertSame(
            ['sMollieTestKey', 'sMollieLiveKey'],
            MollieDefinitions::SECRET_MODULE_SETTINGS,
            'Mollie has no webhook signing secret and no OAuth client secret today. If one appears '
            . '(Mollie Connect), add it here and the rest of this test will force it to be masked.',
        );
    }

    public function testTheOverrideTemplateExists(): void
    {
        self::assertFileExists($this->templatePath(), self::TEMPLATE . ' is the mask; without it every key renders in clear text');
    }

    /**
     * Note what is deliberately NOT asserted here: that each key's literal name appears in the
     * template. It does not, and that is the stronger design — the template branches on
     * `module_var in constant('…::SECRET_MODULE_SETTINGS')`, so adding a key to the constant masks it
     * with no template edit at all. Asserting literal names would have forced a second hardcoded list
     * into the template, which is precisely the drift this test exists to prevent.
     *
     * The guarantee therefore comes from three assertions together: the template renders a masked
     * input at all, it marks it, and it decides *which* fields to mask from the shared constant.
     */
    public function testSecretSettingsAreMaskedByAMechanismDrivenFromTheSharedConstant(): void
    {
        $template = $this->templateSource();

        self::assertStringContainsString('type="password"', $template);
        self::assertStringContainsString('data-mollie-secret', $template);
        self::assertStringContainsString(
            'SECRET_MODULE_SETTINGS',
            $template,
            'the template must decide what to mask from the single source of truth, not from a second '
            . 'hardcoded list that can drift away from it',
        );
    }

    public function testNoSecretSettingIsEverRenderedAsATextInput(): void
    {
        $template = $this->templateSource();

        foreach ($this->textInputBlocks($template) as $block) {
            foreach (MollieDefinitions::SECRET_MODULE_SETTINGS as $setting) {
                self::assertStringNotContainsString(
                    $setting,
                    $block,
                    sprintf('%s appears inside a type="text" input — that is the bug this sprint fixes', $setting),
                );
            }
        }
    }

    public function testEachMaskedFieldHasAnAccessibleToggleButton(): void
    {
        $template = $this->templateSource();

        self::assertStringContainsString('class="mollie-key-toggle"', $template);
        self::assertStringContainsString('type="button"', $template, 'a submit button here would post the form on reveal');
        self::assertStringContainsString('aria-pressed="false"', $template, 'the toggle starts in the masked state');
        self::assertStringContainsString('aria-label=', $template);
        self::assertStringContainsString('data-label-reveal=', $template);
        self::assertStringContainsString('data-label-hide=', $template);
    }

    /**
     * D4: several modules override this same template. Without delegation, whichever override sits
     * later in the resolved chain swallows the others' fields and blanks out their forms.
     */
    public function testTemplateDelegatesEveryNonSecretSettingToTheParentChain(): void
    {
        self::assertStringContainsString(
            '{{ parent() }}',
            $this->templateSource(),
            'non-secret settings — and every other module\'s settings — must fall through',
        );
    }

    /**
     * D2: gate on the stock base-Admin accessor, never on a module-specific view predicate. Calling a
     * sibling module\'s predicate is unreliable against OXID\'s __call-based controllers.
     */
    public function testTemplateGatesOnGetEditObjectIdRatherThanAModulePredicate(): void
    {
        $template = $this->templateSource();

        self::assertStringContainsString('getEditObjectId()', $template);
        self::assertStringContainsString(MollieDefinitions::MODULE_ID, $template);
        self::assertStringNotContainsString('stripeIsStripe', $template);
    }

    // --- metadata reconciliation, both directions ---

    public function testEveryDeclaredSecretIsARealModuleSetting(): void
    {
        $declared = $this->metadataSettingNames();

        foreach (MollieDefinitions::SECRET_MODULE_SETTINGS as $setting) {
            self::assertContains(
                $setting,
                $declared,
                sprintf('%s is masked but is not a setting in metadata.php — a typo masks nothing', $setting),
            );
        }
    }

    /**
     * The direction that actually catches drift: a new credential-looking setting added to
     * metadata.php without being added to the secret list.
     */
    public function testNoCredentialLookingSettingIsLeftOutOfTheSecretList(): void
    {
        $suspicious = array_values(array_filter(
            $this->metadataSettingNames(),
            static fn (string $name): bool => (bool) preg_match('/(key|secret|token|password)$/i', $name),
        ));

        self::assertSame(
            MollieDefinitions::SECRET_MODULE_SETTINGS,
            $suspicious,
            'a setting whose name ends in key/secret/token/password is not masked. Either add it to '
            . 'MollieDefinitions::SECRET_MODULE_SETTINGS, or — if it is genuinely public, like '
            . 'sMollieProfileId — rename it so it does not read as a credential.',
        );
    }

    /**
     * sMollieProfileId is deliberately NOT masked: the pfl_… id is shipped to the browser by the OPC
     * footer widget for Mollie Components, so masking it in admin would imply a confidentiality it
     * does not have. Pinned so the decision is revisited rather than drifted into.
     */
    public function testProfileIdIsDeliberatelyNotSecret(): void
    {
        self::assertNotContains('sMollieProfileId', MollieDefinitions::SECRET_MODULE_SETTINGS);
    }

    /**
     * @return list<string>
     */
    private function metadataSettingNames(): array
    {
        $metadata = $this->moduleRoot() . '/metadata.php';
        self::assertFileExists($metadata);

        $source = (string) file_get_contents($metadata);
        preg_match_all("/'name'\s*=>\s*'(sMollie\w+)'/", $source, $matches);

        return $matches[1];
    }

    /**
     * Crude split into `<input …>` tags carrying type="text", so the "never a text input" assertion
     * looks at tags rather than the whole file.
     *
     * @return list<string>
     */
    private function textInputBlocks(string $template): array
    {
        preg_match_all('/<input[^>]*type="text"[^>]*>/', $template, $matches);

        return $matches[0];
    }

    private function templateSource(): string
    {
        self::assertFileExists($this->templatePath());

        return (string) file_get_contents($this->templatePath());
    }

    private function templatePath(): string
    {
        return $this->moduleRoot() . '/' . self::TEMPLATE;
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
