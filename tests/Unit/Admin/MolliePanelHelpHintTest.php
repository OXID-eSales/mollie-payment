<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * MOL-10 — the order Payment panel labels the contract state "OXID Contract Status" and carries
 * payment-base's "?" hint next to it, fed with Mollie's column.
 */
final class MolliePanelHelpHintTest extends TestCase
{
    public function testTheContractStateRowCarriesTheSharedHintWithMolliesColumn(): void
    {
        $path = dirname(__DIR__, 3) . '/views/twig/admin/panel/mollie_panel.html.twig';
        self::assertFileExists($path);
        $template = (string) file_get_contents($path);

        self::assertSame(1, preg_match('/<th class="pc-label">\s*{{ "MOLLIE_CONTRACT_STATE"\|translate }}(.*?)<\/th>/s', $template, $m), 'the state row exists');
        $cell = $m[1];
        self::assertStringContainsString('@oe_payment_base/admin/help/contract_state_hint.html.twig', $cell, 'the hint sits in the label cell');
        self::assertStringContainsString('intro: help.introIdent', $cell);
        self::assertStringContainsString('providerLabel: help.columnIdent', $cell);
        self::assertStringContainsString('providerStatuses: help.providerStatuses', $cell);
    }
}
