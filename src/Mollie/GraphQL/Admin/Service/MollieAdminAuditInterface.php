<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use Closure;

/**
 * Runs a settings change so that exactly one audit row records it, whatever the outcome.
 */
interface MollieAdminAuditInterface
{
    /**
     * @param list<string>              $changedFields names only; values are never recorded
     * @param Closure(): MollieConfigResult $action
     */
    public function audited(string $operationName, array $changedFields, Closure $action): MollieConfigResult;
}
