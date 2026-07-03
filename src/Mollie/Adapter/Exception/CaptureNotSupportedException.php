<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Exception;

/**
 * Raised when a capture is attempted on a payment/method that does not support two-step capture
 * (most Mollie methods auto-capture). Tested, not silently no-op.
 */
final class CaptureNotSupportedException extends MollieAdapterException
{
}
