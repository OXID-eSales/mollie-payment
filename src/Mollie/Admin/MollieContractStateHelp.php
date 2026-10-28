<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Admin\Help\ContractStateHelpProviderInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Mollie's column of the shared contract-state Help (MOL-10): for every row payment-base shows
 * (OXID Contract Status · Meaning) the Mollie payment status it corresponds to, keyed by the row's
 * first state. The rows, the meanings and the markup live in payment-base; this class adds only what
 * is Mollie's: the column, its header and the description shown above the table. PAY-5: also the provider of the
 * admin API's `adminPaymentContractStateHelp` (tag `oe.payment.admin_state_help`).
 */
final class MollieContractStateHelp implements ContractStateHelpProviderInterface
{
    public const INTRO_IDENT = 'MOLLIE_HELP_CONTRACT_STATES_INTRO';
    public const COLUMN_IDENT = 'MOLLIE_HELP_COL_MOLLIE_STATUS';

    // The header of the column in the admin API's Help (the classic screens translate COLUMN_IDENT).
    private const PROVIDER_LABEL = 'Mollie';

    public function providerName(): string
    {
        return MollieDefinitions::PROVIDER_NAME;
    }

    public function providerLabel(): string
    {
        return self::PROVIDER_LABEL;
    }

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
