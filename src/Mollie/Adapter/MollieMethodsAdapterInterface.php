<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use OxidEsales\Payments\Mollie\Adapter\Dto\MethodsListRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieMethodDto;

/**
 * Payment-method listing (Sprint 7 ISP slice, 5th of the segregated adapter interfaces).
 */
interface MollieMethodsAdapterInterface
{
    /**
     * @return list<MollieMethodDto>
     */
    public function listActiveMethods(MethodsListRequest $request): array;
}
