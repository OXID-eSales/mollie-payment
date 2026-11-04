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
 *
 * The SDK's message is NOT carried over, and neither is the SDK exception as `previous`: the message embeds the
 * request body ("Request body: {...}" with the card token and the addresses), and a chained exception is printed
 * along by every log formatter that sees the outer one. What survives are the HTTP status, the field name (VR-26 M3).
 */
final class MollieExceptionConverter
{
    public static function convert(ApiException $exception): MollieAdapterException
    {
        $status = (int) $exception->getCode();
        $field = $exception->getField();
        $field = is_string($field) && $field !== '' ? $field : null;

        return new MollieAdapterException(
            sprintf('Mollie API call failed (HTTP %d%s)', $status, $field === null ? '' : ', field ' . $field),
            $status,
            null,
            $field,
        );
    }
}
