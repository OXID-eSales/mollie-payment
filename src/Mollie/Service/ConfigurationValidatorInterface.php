<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Validates the module configuration (mode/key consistency) before any API call is attempted.
 */
interface ConfigurationValidatorInterface
{
    public function validate(): ConfigurationValidationResult;
}
