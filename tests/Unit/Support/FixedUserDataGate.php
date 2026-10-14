<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Support;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Payments\Mollie\Service\CheckoutUserDataGateInterface;

/**
 * MOL-15 gate answering a fixed list — empty means "the data may go to Mollie".
 */
final class FixedUserDataGate implements CheckoutUserDataGateInterface
{
    /**
     * @param list<string> $problems
     */
    public function __construct(private readonly array $problems = [])
    {
    }

    public function problemsFor(?User $user): array
    {
        return $this->problems;
    }
}
