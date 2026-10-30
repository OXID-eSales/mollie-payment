<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieAdminSettingsInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigSnapshot;

final class InMemoryMollieSettings implements MollieAdminSettingsInterface
{
    /** @var array<string, string> mode => stored key */
    public array $keys;

    public function __construct(private MollieConfigSnapshot $current)
    {
        $this->keys = ['test' => $current->testKeySet ? ConfigFixtures::TEST_KEY : '', 'live' => ''];
    }

    public function snapshot(): MollieConfigSnapshot
    {
        return $this->current;
    }
}
