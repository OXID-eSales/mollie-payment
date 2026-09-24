<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Immutable value object representing a single field-validation failure.
 *
 * Carries the logical field name, the violation code from
 * {@see \OxidEsales\PaymentBase\Validation\FieldValidationResult}, the offending character (if
 * available), and the corresponding OXID column name.
 */
final class FieldValidationFailure
{
    public const KIND_BILLING = 'billing';
    public const KIND_DELIVERY = 'delivery';
    public const KIND_ADMIN = 'admin';

    /**
     * @param string $addressKind which address the value came from: {@see self::KIND_BILLING},
     *                            {@see self::KIND_DELIVERY} or {@see self::KIND_ADMIN} for admin free text
     */
    public function __construct(
        public readonly string $field,
        public readonly string $addressKind,
        public readonly string $code,
        public readonly ?string $offendingChar,
        public readonly ?string $oxidColumn,
    ) {
    }
}
