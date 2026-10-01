<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Admin\Help\ContractStateHelp;
use OxidEsales\PaymentBase\Admin\Help\ContractStateHelpRow;
use OxidEsales\Payments\Mollie\Admin\MollieContractStateHelp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MOL-10 — Mollie's column of the shared contract-state Help: one Mollie status per payment-base row,
 * no row left without an answer, no answer for a row that does not exist.
 */
#[CoversClass(MollieContractStateHelp::class)]
final class MollieContractStateHelpTest extends TestCase
{
    public function testAnswersEveryPaymentBaseRowAndNothingElse(): void
    {
        $keys = array_map(static fn (ContractStateHelpRow $row): string => $row->key(), (new ContractStateHelp())->rows());
        $statuses = (new MollieContractStateHelp())->providerStatuses();

        self::assertSame($keys, array_keys($statuses), 'one Mollie status per shared row, in the shared order');
    }

    public function testMapsTheContractStatesToMolliesPaymentStatuses(): void
    {
        self::assertSame([
            'not_finished' => 'open',
            'pending' => 'pending',
            'authorized' => 'authorized',
            'ready_to_commit' => 'paid',
            'committed' => '',
            'cancelled' => 'canceled',
            'expired' => 'expired',
            'failed' => 'failed',
        ], (new MollieContractStateHelp())->providerStatuses());
    }

    #[DataProvider('languages')]
    public function testItsIdentsAreTranslatedAndThePanelLabelReadsOxidContractStatus(string $language): void
    {
        $aLang = [];
        require dirname(__DIR__, 3) . "/views/admin_twig/{$language}/mollie_lang.php";
        $help = new MollieContractStateHelp();

        foreach ([$help->introIdent(), $help->columnIdent()] as $ident) {
            self::assertArrayHasKey($ident, $aLang, "$ident missing in $language");
            self::assertNotSame('', trim($aLang[$ident]));
        }
        self::assertSame($language === 'en' ? 'OXID Contract Status' : 'OXID-Vertragsstatus', $aLang['MOLLIE_CONTRACT_STATE']);
        self::assertArrayNotHasKey('MOLLIE_HELP_STATE_NOT_FINISHED', $aLang, 'the meanings live in payment-base now');
    }

    /** @return iterable<string, array{string}> */
    public static function languages(): iterable
    {
        yield 'en' => ['en'];
        yield 'de' => ['de'];
    }
}
