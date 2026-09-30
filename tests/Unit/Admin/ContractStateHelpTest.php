<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\Payments\Mollie\Admin\ContractStateHelp;
use OxidEsales\Payments\Mollie\Admin\ContractStateHelpRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MOL-10 — the "Help" table in the module settings: OXID contract state · meaning · Mollie payment status.
 * The rows are the first three columns of the MOL-10 report table (docs/dev_day_log/20260923/reports).
 */
#[CoversClass(ContractStateHelp::class)]
#[CoversClass(ContractStateHelpRow::class)]
final class ContractStateHelpTest extends TestCase
{
    /** Every state a Mollie checkout can reach, in ladder order; draft never shows (it exists for a blink). */
    private const EXPECTED = [
        // [states in the cell, Mollie status, meaning ident]
        [['not_finished'], 'open', 'MOLLIE_HELP_STATE_NOT_FINISHED'],
        [['pending'], 'pending', 'MOLLIE_HELP_STATE_PENDING'],
        [['authorized'], 'authorized', 'MOLLIE_HELP_STATE_AUTHORIZED'],
        [['ready_to_commit'], 'paid', 'MOLLIE_HELP_STATE_READY_TO_COMMIT'],
        [['committed', 'fulfilled'], '', 'MOLLIE_HELP_STATE_COMMITTED_FULFILLED'],
        [['cancelled'], 'canceled', 'MOLLIE_HELP_STATE_CANCELLED'],
        [['expired'], 'expired', 'MOLLIE_HELP_STATE_EXPIRED'],
        [['failed'], 'failed', 'MOLLIE_HELP_STATE_FAILED'],
    ];

    public function testRowsAreTheReportTableInLadderOrder(): void
    {
        $rows = (new ContractStateHelp())->rows();

        self::assertCount(count(self::EXPECTED), $rows);
        foreach ($rows as $i => $row) {
            self::assertInstanceOf(ContractStateHelpRow::class, $row);
            [$states, $mollie, $ident] = self::EXPECTED[$i];
            self::assertSame($states, $row->states, "row $i states");
            self::assertSame($mollie, $row->mollieStatus, "row $i Mollie status");
            self::assertSame($ident, $row->meaningIdent, "row $i ident");
        }
    }

    public function testEveryContractStateExceptDraftAppearsExactlyOnce(): void
    {
        $shown = array_merge(...array_map(static fn (ContractStateHelpRow $row): array => $row->states, (new ContractStateHelp())->rows()));

        $all = array_map(
            static fn (ContractState $state): string => (string) $state,
            [
                ContractState::notFinished(), ContractState::pending(), ContractState::authorized(),
                ContractState::readyToCommit(), ContractState::committed(), ContractState::fulfilled(),
                ContractState::cancelled(), ContractState::expired(), ContractState::failed(),
            ]
        );
        sort($all);
        $sortedShown = $shown;
        sort($sortedShown);
        self::assertSame($all, $sortedShown, 'the table names every state payment-base knows, once, except draft');
        self::assertNotContains((string) ContractState::draft(), $shown);
    }

    #[DataProvider('languages')]
    public function testEveryIdentTheTableUsesIsTranslated(string $language): void
    {
        $lang = $this->adminLang($language);
        $idents = array_merge(
            ContractStateHelp::SHARED_IDENTS,
            array_map(static fn (ContractStateHelpRow $row): string => $row->meaningIdent, (new ContractStateHelp())->rows())
        );
        foreach ($idents as $ident) {
            self::assertArrayHasKey($ident, $lang, "$ident missing in $language");
            self::assertNotSame('', trim($lang[$ident]), "$ident empty in $language");
        }
    }

    #[DataProvider('languages')]
    public function testThePanelLabelsTheContractStateAsOxidContractStatus(string $language): void
    {
        $lang = $this->adminLang($language);
        self::assertSame(
            $language === 'en' ? 'OXID Contract Status' : 'OXID-Vertragsstatus',
            $lang['MOLLIE_CONTRACT_STATE']
        );
        self::assertSame($lang['MOLLIE_CONTRACT_STATE'], $lang['MOLLIE_HELP_COL_CONTRACT_STATE'], 'panel and help table use one label');
    }

    /** @return iterable<string, array{string}> */
    public static function languages(): iterable
    {
        yield 'en' => ['en'];
        yield 'de' => ['de'];
    }

    /** @return array<string, string> */
    private function adminLang(string $language): array
    {
        $aLang = [];
        require dirname(__DIR__, 3) . '/views/admin_twig/' . $language . '/mollie_lang.php';

        return $aLang;
    }
}
