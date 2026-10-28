<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

/**
 * The Mollie module settings as the admin API reads and writes them (infrastructure seam over the module settings).
 */
interface MollieAdminSettingsInterface
{
    public function snapshot(): MollieConfigSnapshot;

    /**
     * Stores the fields the change carries; a key only when it is a new one.
     */
    public function apply(MollieConfigChange $change): void;
}
