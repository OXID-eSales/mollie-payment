<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Exception;

use RuntimeException;

/**
 * Domain exception raised by the adapter layer. No Mollie SDK exception type ever crosses the
 * adapter boundary — the SDK ApiException is converted into this.
 */
class MollieAdapterException extends RuntimeException
{
}
