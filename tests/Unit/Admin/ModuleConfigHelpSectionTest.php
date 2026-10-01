<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * MOL-10 — the module Settings tab ends with a "Help" group (after "Logging") showing payment-base's
 * contract-state table with Mollie's column. Asserted on the template source, as the masking test does.
 */
final class ModuleConfigHelpSectionTest extends TestCase
{
    private const TEMPLATE = 'views/twig/extensions/themes/admin_twig/module_config.html.twig';

    public function testHelpGroupHooksTheLastSettingsGroupAndOnlyForTheMollieModule(): void
    {
        $template = $this->templateSource();

        self::assertSame(1, preg_match('/{% block admin_module_config_group %}(.*?){% endblock %}/s', $template, $m));
        $block = $m[1];
        self::assertStringContainsString('{{ parent() }}', $block, 'every settings group still renders through the stock chain');
        self::assertStringContainsString('loop.last', $block, 'the Help group follows the last settings group (Logging)');
        self::assertStringContainsString("getEditObjectId() == 'oe_payments_mollie'", $block);
        self::assertStringContainsString('class="groupExp"', $block);
        self::assertStringContainsString("'PAYMENT_ADMIN_HELP'", $block, 'the title is the shared one');
    }

    public function testHelpTableIsTheSharedOneWithMolliesColumn(): void
    {
        $template = $this->templateSource();

        self::assertStringContainsString('@oe_payment_base/admin/help/contract_state_table.html.twig', $template, 'payment-base owns the table');
        self::assertStringContainsString('oViewConf.getMollieContractStateHelp()', $template);
        self::assertStringContainsString('providerLabel: help.columnIdent', $template);
        self::assertStringContainsString('providerStatuses: help.providerStatuses', $template);
        self::assertStringContainsString('help.introIdent', $template, 'the description above the table is Mollie\'s');
        self::assertStringNotContainsString('row.meaningIdent', $template, 'no second table in Twig');
    }

    private function templateSource(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::TEMPLATE;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
