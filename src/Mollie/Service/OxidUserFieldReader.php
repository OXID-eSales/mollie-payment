<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\Address;
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
        'postalCode' => 'oxzip',
        'city' => 'oxcity',
        'company' => 'oxcompany',
        'vatId' => 'oxustid',
        'additionalInfo' => 'oxaddinfo',
        'phone' => 'oxfon',
        'cellPhone' => 'oxprivfon',
        'personalPhone' => 'oxmobfon',
        'fax' => 'oxfax',
        'email' => 'oxusername',
    ];

    private ?Address $deliveryAddress = null;

    public function __construct(private readonly User $user)
    {
        // MOL-15: a selected delivery address is sent to the PSP as well, so it is validated too.
        $address = $user->getSelectedAddress();
        $this->deliveryAddress = $address instanceof Address ? $address : null;
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
    public function hasDeliveryAddress(): bool
    {
        return $this->deliveryAddress !== null;
    }

    public function readDeliveryField(string $logicalName): string
    {
        $column = self::MAP[$logicalName] ?? null;
        if ($column === null || $this->deliveryAddress === null) {
            return '';
        }

        $value = $this->deliveryAddress->getFieldData($column);

        return is_string($value) ? $value : '';
    }

    public static function oxidColumn(string $logicalName): ?string
    {
        return self::MAP[$logicalName] ?? null;
    }
}
