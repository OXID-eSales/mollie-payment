<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Gateway;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Validation\User\FieldValidationFailure;
use OxidEsales\PaymentBase\Validation\User\UserDataValidatorInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * MOL-15: admin free text goes to Mollie with a capture or a refund, so it runs through the checkout's character
 * rules (address kind `admin`). payment-base's money runner checks only length and control characters.
 */
final class GatewayTextRules
{
    public function __construct(
        private readonly UserDataValidatorInterface $validator,
        private readonly GatewayRefusals $refusals,
    ) {
    }

    public function refusalFor(string $field, ?string $text): ?AdminActionRefusedException
    {
        if ($text === null) {
            return null;
        }
        $failures = $this->validator->validateFieldMap(
            MollieDefinitions::MODULE_ID,
            [$field => $text],
            FieldValidationFailure::KIND_ADMIN,
        );
        if ($failures === []) {
            return null;
        }

        return $this->refusals->text(array_values($failures));
    }
}
