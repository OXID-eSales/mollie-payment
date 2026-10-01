<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use DateTimeInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * The shop's own record that the uncaptured remainder of a Mollie authorization was released.
 *
 * Mollie accepts `release-authorization` asynchronously: right after the call the payment
 * resource still reads `authorized` with the old remaining amount, exactly as it does right
 * after a capture (2026-10-01). The admin panel and the capture/cancel services read this
 * marker so a released hold is never offered for capture or release a second time.
 *
 * Kept on the contract's metadata bag (not a state): after partial captures the contract is
 * FULFILLED for the captured amount and stays so — only the remainder of the hold is gone.
 */
final class AuthorizationReleaseMarker
{
    public const METADATA_KEY = 'mollie_authorization_released';

    public static function isReleased(PaymentContractInterface $contract): bool
    {
        return $contract->getMetadata(self::METADATA_KEY) !== null;
    }

    public static function mark(
        PaymentContractInterface $contract,
        float $releasedAmount,
        ?string $reason,
        DateTimeInterface $releasedAt,
    ): void {
        $contract->setMetadata(self::METADATA_KEY, [
            'amount' => round($releasedAmount, 2),
            'reason' => $reason,
            'releasedAt' => $releasedAt->format(DateTimeInterface::ATOM),
        ]);
    }
}
