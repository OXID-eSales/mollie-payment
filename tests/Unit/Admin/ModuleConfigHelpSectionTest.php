<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * MOL-10 — the module Settings tab ends with a "Help" group (after "Logging") that explains the OXID
 * contract states and their Mollie payment status. Asserted on the template source, as the masking
 * test does: the Unit suite renders no admin templates.
 */
final class ModuleConfigHelpSectionTest extends TestCase
{
    private const TEMPLATE = 'views/twig/extensions/themes/admin_twig/module_config.html.twig';

    public function testHelpGroupHooksTheLastSettingsGroupAndOnlyForTheMollieModule(): void
    {
        $template = $this->templateSource();

        self::assertSame(1, preg_match('/{% block admin_module_config_group %}(.*?){% endblock %}/s', $template, $m), 'the group block is overridden');
        $block = $m[1];
        self::assertStringContainsString('{{ parent() }}', $block, 'every settings group still renders through the stock chain');
        self::assertStringContainsString('loop.last', $block, 'the Help group follows the last settings group (Logging)');
        self::assertStringContainsString("getEditObjectId() == 'oe_payments_mollie'", $block, 'other modules\' settings pages stay untouched');
        self::assertStringContainsString('class="groupExp"', $block, 'same collapsible markup as the stock groups');
        self::assertStringContainsString('_groupExp(this)', $block);
    }

    public function testHelpTableIsDrivenByTheViewConfigRowsAndTranslatedIdents(): void
    {
        $template = $this->templateSource();

        self::assertStringContainsString('oViewConf.getMollieContractStateHelp()', $template, 'rows come from one PHP source, not a second table in Twig');
        foreach (['MOLLIE_HELP', 'MOLLIE_HELP_CONTRACT_STATES_INTRO', 'MOLLIE_HELP_COL_CONTRACT_STATE', 'MOLLIE_HELP_COL_MEANING', 'MOLLIE_HELP_COL_MOLLIE_STATUS', 'MOLLIE_HELP_MOLLIE_NONE'] as $ident) {
            self::assertStringContainsString("'$ident'", $template, "$ident is translated in the template");
        }
        self::assertStringContainsString('row.meaningIdent', $template, 'the meaning is translated per row');
        self::assertStringContainsString('row.mollieStatus', $template);
        self::assertStringContainsString('row.states', $template);
    }

    private function templateSource(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::TEMPLATE;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
