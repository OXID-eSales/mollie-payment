<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Support;

use OxidEsales\Payments\Mollie\Service\InFlightCheckoutReplayInterface;

/**
 * Replay port of a session with no in-flight attempt: there is nothing to rejoin.
 */
final class NoInFlightReplay implements InFlightCheckoutReplayInterface
{
    public function checkoutUrl(): ?string
    {
        return null;
    }
}
