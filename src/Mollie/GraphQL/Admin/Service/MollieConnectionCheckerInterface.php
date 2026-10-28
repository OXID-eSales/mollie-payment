<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

interface MollieConnectionCheckerInterface
{
    /**
     * Asks Mollie (GET profile) whether the API key of the active mode is accepted. Never throws for a failed
     * check: every outcome is a status.
     */
    public function check(): MollieConnectionStatus;
}
