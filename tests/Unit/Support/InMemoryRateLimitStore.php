<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Support;

use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitStoreInterface;

/**
 * Counts hits per key for the lifetime of the test. payment-base's own double is test-support there and not
 * autoloaded in this module's CI, so the module keeps this one.
 */
final class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string, int> */
    private array $counters = [];

    public function increment(string $key, int $ttlSeconds): int
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;

        return $this->counters[$key];
    }
}
