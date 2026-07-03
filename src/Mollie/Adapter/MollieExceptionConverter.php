<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use Mollie\Api\Exceptions\ApiException;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;

/**
 * Converts Mollie SDK exceptions into the module's domain exception so no SDK exception type
 * leaks past the adapter boundary.
 */
final class MollieExceptionConverter
{
    public static function convert(ApiException $exception): MollieAdapterException
    {
        return new MollieAdapterException($exception->getMessage(), (int) $exception->getCode(), $exception);
    }
}
