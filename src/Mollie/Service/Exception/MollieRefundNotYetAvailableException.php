<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service\Exception;

use RuntimeException;
use Throwable;

/**
 * MOL-30: the shop has captured, Mollie has not settled the capture yet, and Mollie refused the
 * refund for that reason. Distinct from every other refund failure so the admin can be told to try
 * again in a moment instead of being shown a generic error.
 */
final class MollieRefundNotYetAvailableException extends RuntimeException
{
    public static function whileSettling(string $paymentId, Throwable $cause): self
    {
        return new self(
            sprintf('Mollie has not settled the capture of payment "%s" yet; the refund was refused.', $paymentId),
            (int) $cause->getCode(),
            $cause,
        );
    }
}
