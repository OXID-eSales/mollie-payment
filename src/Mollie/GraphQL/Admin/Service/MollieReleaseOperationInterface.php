<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;

interface MollieReleaseOperationInterface
{
    /**
     * Releases the still-authorized remainder of a partially captured Mollie payment (MOL-32). Needs a step-up;
     * audited and idempotent like payment-base's money operations.
     */
    public function release(string $orderId, ?string $reason, ?string $idempotencyKey): PaymentOperationResult;
}
