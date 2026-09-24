<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Validation\ValidationBaseFactory;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Runs the shopper's address data through payment-base's character-level validation engine, bound to
 * Mollie's own rules file (`src/Resources/validation-rules.php`, module id {@see MollieDefinitions::MODULE_ID}).
 *
 * MOL-15: the billing address is validated first; when the shopper selected a separate delivery
 * address, that one is validated too - both are sent to the PSP. Admin free text goes through
 * {@see validateFieldMap()} with {@see FieldValidationFailure::KIND_ADMIN}.
 */
final class UserDataValidator implements UserDataValidatorInterface
{
    /**
     * The logical fields the rules file declares for the shopper. `email` is Mollie-specific:
     * Mollie receives the shopper's e-mail address with the payment.
     */
    private const LOGICAL_FIELDS = [
        'firstName', 'lastName', 'additionalInfo', 'street', 'houseNumber',
        'postalCode', 'city', 'company', 'vatId', 'phone', 'cellPhone',
        'personalPhone', 'fax', 'email',
    ];

    public function __construct(
        private readonly ValidationBaseFactory $factory,
    ) {
    }

    /**
     * @return list<FieldValidationFailure>
     */
    public function validateForUser(UserFieldReaderInterface $reader): array
    {
        $failures = $this->validateAddressPass($reader, FieldValidationFailure::KIND_BILLING);
        if ($reader->hasDeliveryAddress()) {
            $failures = array_merge(
                $failures,
                $this->validateAddressPass($reader, FieldValidationFailure::KIND_DELIVERY)
            );
        }

        return $failures;
    }

    /**
     * @param array<string, string> $fields
     *
     * @return list<FieldValidationFailure>
     */
    public function validateFieldMap(array $fields, string $addressKind = FieldValidationFailure::KIND_BILLING): array
    {
        $failures = [];
        foreach ($fields as $logicalName => $value) {
            $failure = $this->validateSingleField($logicalName, $value, $addressKind, null);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /**
     * @return list<FieldValidationFailure>
     */
    private function validateAddressPass(UserFieldReaderInterface $reader, string $addressKind): array
    {
        $failures = [];
        foreach (self::LOGICAL_FIELDS as $logicalName) {
            $value = $addressKind === FieldValidationFailure::KIND_BILLING
                ? $reader->readBillingField($logicalName)
                : $reader->readDeliveryField($logicalName);
            $failure = $this->validateSingleField(
                $logicalName,
                $value,
                $addressKind,
                OxidUserFieldReader::oxidColumn($logicalName)
            );
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    private function validateSingleField(
        string $logicalName,
        string $value,
        string $addressKind,
        ?string $oxidColumn,
    ): ?FieldValidationFailure {
        if ($value === '') {
            return null;
        }

        $result = $this->factory->create(MollieDefinitions::MODULE_ID)->validateField($logicalName, $value);
        if ($result->valid) {
            return null;
        }

        return new FieldValidationFailure(
            field: $logicalName,
            addressKind: $addressKind,
            code: (string) $result->code,
            offendingChar: $result->offendingChar,
            oxidColumn: $oxidColumn,
        );
    }
}
