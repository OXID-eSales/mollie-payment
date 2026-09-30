<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

/**
 * One row of the "Help" table in the module settings (MOL-10): the OXID contract state(s) shown in
 * the first cell, the Mollie payment status that corresponds to it ('' = none, shop-internal), and
 * the translation ident of the meaning.
 */
final class ContractStateHelpRow
{
    /**
     * @param list<string> $states
     */
    public function __construct(
        public readonly array $states,
        public readonly string $mollieStatus,
        public readonly string $meaningIdent,
    ) {
    }
}
