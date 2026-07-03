<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Exception;

/**
 * Raised when the module cannot build a Mollie client because credentials are missing/malformed.
 * Fails fast and typed before any API call is attempted.
 */
final class MollieConfigurationException extends MollieAdapterException
{
}
