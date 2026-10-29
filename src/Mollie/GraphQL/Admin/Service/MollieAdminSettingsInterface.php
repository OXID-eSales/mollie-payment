<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

/**
 * The Mollie module settings as the admin API reads them (infrastructure seam over the module settings).
 */
interface MollieAdminSettingsInterface
{
    public function snapshot(): MollieConfigSnapshot;
}
