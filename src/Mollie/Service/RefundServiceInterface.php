<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;

/**
 * Admin-initiated refund orchestrator (full & partial, accumulating).
 */
interface RefundServiceInterface
{
    /**
     * Refund a fulfilled Mollie payment, full or partial.
     *
     * @throws \DomainException if the contract is not FULFILLED or has no Mollie payment id
     * @throws \InvalidArgumentException if the requested amount is not a positive number within
     *                                    the Mollie-reported refundable amount
     */
    public function refund(
        PaymentContractInterface $contract,
        ?float $amount = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): MollieRefundDto;
}
