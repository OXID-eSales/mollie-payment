<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

/**
 * Why the admin API did not do what was asked: the input is not acceptable (a validation error of one field) or the
 * settings changed since the admin read them (a conflict). The message never carries a value.
 */
final readonly class MollieAdminRefusal
{
    public const INVALID = 'invalid';
    public const CONFLICT = 'conflict';

    private function __construct(
        public string $kind,
        public string $field,
        public string $code,
        public string $message,
        public string $expectedVersion = '',
        public string $actualVersion = '',
    ) {
    }

    public static function invalid(string $field, string $code, string $message): self
    {
        return new self(self::INVALID, $field, $code, $message);
    }

    public static function conflict(string $expectedVersion, string $actualVersion): self
    {
        return new self(
            self::CONFLICT,
            'version',
            'conflict',
            'The Mollie settings were changed by someone else',
            $expectedVersion,
            $actualVersion
        );
    }
}
