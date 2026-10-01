<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

/**
 * Mollie's column of the shared contract-state Help (MOL-10): for every row payment-base shows
 * (OXID Contract Status · Meaning) the Mollie payment status it corresponds to, keyed by the row's
 * first state. The rows, the meanings and the markup live in payment-base; this class adds only what
 * is Mollie's: the column, its header and the description shown above the table.
 */
final class MollieContractStateHelp
{
    public const INTRO_IDENT = 'MOLLIE_HELP_CONTRACT_STATES_INTRO';
    public const COLUMN_IDENT = 'MOLLIE_HELP_COL_MOLLIE_STATUS';

    public function introIdent(): string
    {
        return self::INTRO_IDENT;
    }

    public function columnIdent(): string
    {
        return self::COLUMN_IDENT;
    }

    /**
     * Row key (first contract state of the row) => Mollie payment status; '' = none, shop-internal.
     *
     * @return array<string, string>
     */
    public function providerStatuses(): array
    {
        return [
            'not_finished' => 'open',
            'pending' => 'pending',
            'authorized' => 'authorized',
            'ready_to_commit' => 'paid',
            'committed' => '',
            'cancelled' => 'canceled',
            'expired' => 'expired',
            'failed' => 'failed',
        ];
    }
}
