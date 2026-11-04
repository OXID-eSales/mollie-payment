<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Support;

use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;

/**
 * payment-base's "use iframe checkout" flag answering a fixed value.
 */
final class FixedIframeSettings implements IframeCheckoutSettingsInterface
{
    public function __construct(private readonly bool $enabled = false)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
