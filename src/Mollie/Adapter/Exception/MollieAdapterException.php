<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Exception;

use RuntimeException;
use Throwable;

/**
 * Domain exception raised by the adapter layer. No Mollie SDK exception type ever crosses the
 * adapter boundary — the SDK ApiException is converted into this.
 *
 * It carries the facts of an API failure that are safe to log ({@see $field}: the NAME of the offending field; the
 * HTTP status is the exception code) and never the SDK's text: that text embeds the request body (card token,
 * addresses). {@see \OxidEsales\Payments\Mollie\Adapter\MollieApiExceptionSummary} reads them.
 */
class MollieAdapterException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
