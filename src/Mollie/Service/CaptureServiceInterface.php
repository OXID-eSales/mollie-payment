<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;

/**
 * Admin-initiated capture of an authorized two-step Mollie payment (card/Klarna).
 */
interface CaptureServiceInterface
{
    /**
     * Capture an authorized payment, full (null amount) or partial.
     *
     * @throws \DomainException if the contract is not AUTHORIZED, is already captured, or has
     *                           no Mollie payment id on record
     * @throws \InvalidArgumentException if the requested amount is not a positive number within
     *                                    the Mollie-reported capturable amount
     * @throws \OxidEsales\Payments\Mollie\Adapter\Exception\CaptureNotSupportedException if the
     *                                    payment method does not support two-step capture
     */
    public function capture(
        PaymentContractInterface $contract,
        ?float $amount = null,
        ?string $idempotencyKey = null,
    ): MollieCaptureDto;
}
