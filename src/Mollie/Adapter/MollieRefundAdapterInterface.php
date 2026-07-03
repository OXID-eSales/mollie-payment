<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;

/**
 * Refund operations (ISP slice 3 of 4).
 */
interface MollieRefundAdapterInterface
{
    public function createRefund(RefundRequest $request): MollieRefundDto;

    public function getRefund(string $paymentId, string $refundId): MollieRefundDto;

    /**
     * All refunds recorded against a payment — the admin transaction-history source of truth.
     *
     * @return list<MollieRefundDto>
     */
    public function listRefunds(string $paymentId): array;
}
