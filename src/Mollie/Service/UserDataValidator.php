<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Validation\ValidationBaseFactory;
use OxidEsales\PaymentBase\Validation\ValidationBaseInterface;

/**
 * Maps OXID user fields to logical names and delegates character-level validation to
 * payment-base's shared ValidationBase.
 *
 * The reader is a method argument (not a constructor dependency) so this validator stays a
 * stateless DI singleton while the reader is bound to the request's live User object.
 */
final class UserDataValidator implements UserDataValidatorInterface
{
    /** Logical field names collected/sent for Mollie's create-payment call. */
    private const LOGICAL_FIELDS = [
        'firstName', 'lastName', 'street', 'houseNumber', 'zip', 'city',
        'company', 'vatId', 'additionalInfo', 'phone', 'email',
    ];

    public function __construct(
        private readonly ValidationBaseFactory $factory,
    ) {
    }

    public function validateForUser(UserFieldReaderInterface $reader): array
    {
        $failures = [];

        foreach (self::LOGICAL_FIELDS as $logicalName) {
            $failure = $this->validateSingleField(
                $logicalName,
                $reader->readBillingField($logicalName),
                OxidUserFieldReader::oxidColumn($logicalName),
            );
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    public function validateFieldMap(array $fields): array
    {
        $failures = [];

        foreach ($fields as $logicalName => $value) {
            $failure = $this->validateSingleField($logicalName, $value, null);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    private function validateSingleField(
        string $logicalName,
        string $value,
        ?string $oxidColumn,
    ): ?FieldValidationFailure {
        if ($value === '') {
            return null;
        }

        $validationBase = $this->factory->create('oe_payments_mollie');
        $result = $validationBase->validateField($logicalName, $value);
        if ($result->valid) {
            return null;
        }

        return new FieldValidationFailure(
            field: $logicalName,
            code: (string) $result->code,
            offendingChar: $result->offendingChar,
            oxidColumn: $oxidColumn,
        );
    }
}
