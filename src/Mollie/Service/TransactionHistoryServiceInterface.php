<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Service\Result\TransactionRow;

interface TransactionHistoryServiceInterface
{
    /**
     * Produces the admin transaction-history rows for a contract, read live from the Mollie API
     * (the source of truth — reflects actions taken directly in the Mollie merchant dashboard,
     * not just what this shop's webhook pipeline has recorded).
     *
     * @return list<TransactionRow>
     */
    public function fetch(PaymentContractInterface $contract): array;
}
