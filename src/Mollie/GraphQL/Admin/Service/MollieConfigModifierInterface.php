<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

interface MollieConfigModifierInterface
{
    public function modify(MollieConfigChange $change): MollieConfigResult;
}
