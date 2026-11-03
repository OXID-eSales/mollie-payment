<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use DomainException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * The one way a money action reads the Mollie payment id off a contract (review 2026-10-14,
 * A-M3: one guard, differing only in the action word). Capture, refund and void have their own gateways since
 * VR-22 A6; the release action is its only user left.
 */
final class ProviderOrderId
{
    /**
     * @param string $action names the refused money action in the message ("capture", "refund", …)
     *
     * @throws DomainException when the contract never recorded a Mollie payment id
     */
    public static function requireFrom(PaymentContractInterface $contract, string $action): string
    {
        $providerOrderId = $contract->getProviderOrderId();
        if ($providerOrderId === null || $providerOrderId === '') {
            throw new DomainException(sprintf(
                'Cannot %s contract "%s": no Mollie payment id on record.',
                $action,
                $contract->getId() ?? 'unknown',
            ));
        }

        return $providerOrderId;
    }
}
