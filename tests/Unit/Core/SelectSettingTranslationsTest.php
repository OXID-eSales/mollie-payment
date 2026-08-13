<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Every `select` module setting needs a translation for its LABEL and for each of its OPTION values,
 * in every shipped language.
 *
 * The stock admin template renders option labels as `SHOP_MODULE_<setting>_<value>`
 * (`module_config.html.twig:77`), and OXID renders a missing ident as the literal string
 * `ERROR: Translation not found` inside the `<option>`. That is exactly what `sMollieLogLevel` was
 * doing: four options, none of them translated, so the Log level select read "ERROR: Translation not
 * found" on every shop — visible in every screenshot taken for Sprint 10's walkthrough, and shipped
 * that way because nothing checked.
 *
 * It is a two-minute fix and an easy one to reintroduce: add a `select` setting, forget its option
 * idents, and nothing fails. Hence this guard. It derives the expectation from `metadata.php` itself
 * rather than a hardcoded list, so a new setting or a new constraint value is covered the moment it is
 * declared.
 */
#[Group('translations')]
final class SelectSettingTranslationsTest extends TestCase
{
    /**
     * Languages the module ships admin translations for.
     *
     * @return list<string>
     */
    private const LANGUAGES = ['en', 'de'];

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function languages(): iterable
    {
        foreach (self::LANGUAGES as $language) {
            yield $language => [$language];
        }
    }

    #[DataProvider('languages')]
    public function testEverySelectSettingHasALabelAndAnIdentForEachOption(string $language): void
    {
        $lang = $this->adminLang($language);
        $missing = [];

        foreach ($this->selectSettings() as $name => $values) {
            $label = 'SHOP_MODULE_' . $name;
            if (!isset($lang[$label]) || trim($lang[$label]) === '') {
                $missing[] = $label;
            }

            foreach ($values as $value) {
                $ident = 'SHOP_MODULE_' . $name . '_' . $value;
                if (!isset($lang[$ident]) || trim($lang[$ident]) === '') {
                    $missing[] = $ident;
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            sprintf(
                "Untranslated select idents in views/admin_twig/%s/mollie_lang.php — OXID renders each of "
                . "these as the literal text \"ERROR: Translation not found\" inside the <option>:\n  %s",
                $language,
                implode("\n  ", $missing),
            ),
        );
    }

    /**
     * Group headers fail exactly the same way, and for the same reason: the stock template renders
     * `SHOP_MODULE_GROUP_<group>` (`module_config.html.twig:45`), so a new settings group with no ident
     * puts "ERROR: Translation not found" where the section heading should be.
     *
     * Caught in practice: `MOLLIE_ADVANCED` arrived with an untranslated header *and* an untranslated
     * select, hours after this guard was written. It only flagged half of it.
     */
    #[DataProvider('languages')]
    public function testEverySettingGroupHasAHeaderIdent(string $language): void
    {
        $lang = $this->adminLang($language);
        $missing = [];

        foreach ($this->settingGroups() as $group) {
            $ident = 'SHOP_MODULE_GROUP_' . $group;
            if (!isset($lang[$ident]) || trim($lang[$ident]) === '') {
                $missing[] = $ident;
            }
        }

        self::assertSame(
            [],
            $missing,
            sprintf(
                "Untranslated group headers in views/admin_twig/%s/mollie_lang.php:\n  %s",
                $language,
                implode("\n  ", $missing),
            ),
        );
    }

    /**
     * The bug this file exists for, pinned by name so the fix cannot silently regress.
     */
    #[DataProvider('languages')]
    public function testLogLevelOptionsAreTranslated(string $language): void
    {
        $lang = $this->adminLang($language);

        foreach (['off', 'errors', 'normal', 'debug'] as $level) {
            $ident = 'SHOP_MODULE_sMollieLogLevel_' . $level;
            self::assertArrayHasKey($ident, $lang, $ident . ' is missing from the ' . $language . ' file');
            self::assertNotSame('', trim($lang[$ident]));
        }
    }

    public function testBothLanguagesCoverTheSameSelectIdents(): void
    {
        $identsFor = static function (array $lang): array {
            $idents = array_values(array_filter(
                array_keys($lang),
                static fn (string $key): bool => str_starts_with($key, 'SHOP_MODULE_sMollie'),
            ));
            sort($idents);

            return $idents;
        };

        self::assertSame(
            $identsFor($this->adminLang('en')),
            $identsFor($this->adminLang('de')),
            'the en and de admin files must cover the same module-setting idents — a value translated in '
            . 'one language and missing in the other renders the error text for half the operators',
        );
    }

    /**
     * Select settings and their permitted values, read from metadata.php.
     *
     * metadata.php is included rather than regex-parsed so the `MollieDefinitions::` constants in the
     * `constraints` strings resolve themselves; it only assigns variables, so including it is safe.
     *
     * @return array<string, list<string>>
     */
    private function selectSettings(): array
    {
        $aModule = $this->moduleMetadata();
        $selects = [];

        /** @var array<int, array<string, mixed>> $settings */
        $settings = $aModule['settings'] ?? [];
        foreach ($settings as $setting) {
            if (($setting['type'] ?? '') !== 'select') {
                continue;
            }

            $name = (string) ($setting['name'] ?? '');
            $constraints = (string) ($setting['constraints'] ?? '');
            if ($name === '' || $constraints === '') {
                continue;
            }

            $selects[$name] = array_values(array_filter(explode('|', $constraints)));
        }

        self::assertNotSame([], $selects, 'no select settings found — metadata.php parsing has gone stale');

        return $selects;
    }

    /**
     * Every distinct settings group declared in metadata.php.
     *
     * @return list<string>
     */
    private function settingGroups(): array
    {
        $aModule = $this->moduleMetadata();
        $groups = [];

        /** @var array<int, array<string, mixed>> $settings */
        $settings = $aModule['settings'] ?? [];
        foreach ($settings as $setting) {
            $group = (string) ($setting['group'] ?? '');
            if ($group !== '' && !in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        self::assertNotSame([], $groups, 'no setting groups found — metadata.php parsing has gone stale');

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleMetadata(): array
    {
        $path = $this->moduleRoot() . '/metadata.php';
        self::assertFileExists($path);

        $load = static function (string $file): array {
            require $file;
            /** @var array<string, mixed> $aModule */
            return $aModule;
        };

        return $load($path);
    }

    /**
     * @return array<string, string>
     */
    private function adminLang(string $language): array
    {
        $path = sprintf('%s/views/admin_twig/%s/mollie_lang.php', $this->moduleRoot(), $language);
        self::assertFileExists($path);

        $load = static function (string $file): array {
            require $file;
            /** @var array<string, string> $aLang */
            return $aLang;
        };

        return $load($path);
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
