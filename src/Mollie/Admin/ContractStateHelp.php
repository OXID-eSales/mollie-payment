<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Contract\ContractState;

/**
 * The "Help" table of the module settings (MOL-10): OXID Contract Status · Meaning · Mollie payment
 * status — the first three columns of the MOL-10 state report
 * (docs/dev_day_log/20260923/reports/MOL-10-contract-order-transactioon-states.md), in ladder order.
 *
 * States are taken from payment-base's ContractState factories, so a renamed state fails the unit
 * test instead of silently drifting from the panel. `draft` is not listed: it exists for a blink
 * before the checkout-session event and never shows in the panel.
 */
final class ContractStateHelp
{
    /** Translation idents the table needs besides the per-row meanings (title, intro, headers, "none"). */
    public const SHARED_IDENTS = [
        'MOLLIE_HELP',
        'MOLLIE_HELP_CONTRACT_STATES_INTRO',
        'MOLLIE_HELP_COL_CONTRACT_STATE',
        'MOLLIE_HELP_COL_MEANING',
        'MOLLIE_HELP_COL_MOLLIE_STATUS',
        'MOLLIE_HELP_MOLLIE_NONE',
    ];

    /**
     * @return list<ContractStateHelpRow>
     */
    public function rows(): array
    {
        return [
            $this->row([ContractState::notFinished()], 'open', 'MOLLIE_HELP_STATE_NOT_FINISHED'),
            $this->row([ContractState::pending()], 'pending', 'MOLLIE_HELP_STATE_PENDING'),
            $this->row([ContractState::authorized()], 'authorized', 'MOLLIE_HELP_STATE_AUTHORIZED'),
            $this->row([ContractState::readyToCommit()], 'paid', 'MOLLIE_HELP_STATE_READY_TO_COMMIT'),
            $this->row(
                [ContractState::committed(), ContractState::fulfilled()],
                '',
                'MOLLIE_HELP_STATE_COMMITTED_FULFILLED'
            ),
            $this->row([ContractState::cancelled()], 'canceled', 'MOLLIE_HELP_STATE_CANCELLED'),
            $this->row([ContractState::expired()], 'expired', 'MOLLIE_HELP_STATE_EXPIRED'),
            $this->row([ContractState::failed()], 'failed', 'MOLLIE_HELP_STATE_FAILED'),
        ];
    }

    /**
     * @param list<ContractState> $states
     */
    private function row(array $states, string $mollieStatus, string $meaningIdent): ContractStateHelpRow
    {
        return new ContractStateHelpRow(
            array_map(static fn (ContractState $state): string => $state->getValue(), $states),
            $mollieStatus,
            $meaningIdent
        );
    }
}
