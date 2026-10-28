<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminSuperadminCheckInterface;

final class FakeSuperadminCheck implements AdminSuperadminCheckInterface
{
    public int $asked = 0;

    public function __construct(private readonly bool $superadmin = true)
    {
    }

    public function isSuperadmin(): bool
    {
        ++$this->asked;

        return $this->superadmin;
    }
}
