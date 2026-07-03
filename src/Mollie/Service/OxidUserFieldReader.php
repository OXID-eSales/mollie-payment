<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\User;

/**
 * OXID-backed implementation of {@see UserFieldReaderInterface}.
 *
 * Reads field values from a live OXID User model via getFieldData(). The mapping from logical
 * names to OXID column names lives in the static MAP constant so there is a single authoritative
 * place to update if a column name ever changes.
 *
 * Not autowired (see services.yaml exclude) — it is bound to the specific User object supplied
 * at construction time, so callers build it per-request.
 */
final class OxidUserFieldReader implements UserFieldReaderInterface
{
    /**
     * Logical name => OXID column name (oxuser__ prefix).
     *
     * @var array<string, string>
     */
    private const MAP = [
        'firstName' => 'oxfname',
        'lastName' => 'oxlname',
        'street' => 'oxstreet',
        'houseNumber' => 'oxstreetnr',
        'zip' => 'oxzip',
        'city' => 'oxcity',
        'company' => 'oxcompany',
        'vatId' => 'oxustid',
        'additionalInfo' => 'oxaddinfo',
        'phone' => 'oxfon',
        'email' => 'oxusername',
    ];

    public function __construct(private readonly User $user)
    {
    }

    public function readBillingField(string $logicalName): string
    {
        $column = self::MAP[$logicalName] ?? null;
        if ($column === null) {
            return '';
        }

        /** @phpstan-ignore-next-line OXID core: getFieldData() on virtual User object */
        $value = $this->user->getFieldData($column);

        return is_string($value) ? $value : '';
    }

    /**
     * Returns the OXID column name for a logical field name, or null when unknown.
     */
    public static function oxidColumn(string $logicalName): ?string
    {
        return self::MAP[$logicalName] ?? null;
    }
}
