<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\DataType;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ConflictError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ValidationError;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieAdminRefusal;

/**
 * Turns a refusal of the service layer into the admin API's error types.
 */
final class MollieAdminErrors
{
    public static function from(MollieAdminRefusal $refusal): AdminError
    {
        if ($refusal->kind === MollieAdminRefusal::CONFLICT) {
            return new ConflictError($refusal->expectedVersion, $refusal->actualVersion, $refusal->message);
        }

        return new ValidationError($refusal->field, $refusal->code, $refusal->message);
    }
}
