<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Validates the user/address fields Mollie collects against the shared payment-base
 * character-level rules (src/Resources/validation-rules.php, plugin id 'oe_payments_mollie').
 */
interface UserDataValidatorInterface
{
    /**
     * Validates every field read via the supplied field reader.
     *
     * Returns an empty array when all fields pass.
     *
     * @return FieldValidationFailure[]
     */
    public function validateForUser(UserFieldReaderInterface $reader): array;

    /**
     * Validates a flat map of logical-name => value pairs (used by the frontend validation
     * endpoint, which posts logical field names directly).
     *
     * @param array<string, string> $fields
     * @return FieldValidationFailure[]
     */
    public function validateFieldMap(array $fields): array;
}
