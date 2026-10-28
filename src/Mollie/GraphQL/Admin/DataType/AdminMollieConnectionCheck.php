<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\DataType;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConnectionStatus;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * Mollie's verdict on the API key of the active mode. Carries no key and nothing Mollie said: only the verdict.
 */
#[Type(name: 'AdminMollieConnectionCheck')]
final class AdminMollieConnectionCheck
{
    public function __construct(private readonly MollieConnectionStatus $connection)
    {
    }

    /** `ok`, `invalid_key` (Mollie refused it), `unreachable` (no verdict) or `not_configured` (no key for the mode). */
    #[Field]
    public function status(): string
    {
        return $this->connection->status;
    }

    #[Field]
    public function ok(): bool
    {
        return $this->connection->isOk();
    }

    /** The mode the check was made for (`test` or `live`); empty when the settings cannot be read. */
    #[Field]
    public function mode(): string
    {
        return $this->connection->mode;
    }
}
