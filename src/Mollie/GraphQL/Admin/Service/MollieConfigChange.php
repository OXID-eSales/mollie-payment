<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use SensitiveParameter;

/**
 * What an admin asks to change. A field that is null stays as it is; a key that is null or empty stays as it is
 * (write-only: there is no way to read one back, so "empty" cannot mean "clear it").
 */
final readonly class MollieConfigChange
{
    private const FIELDS = [
        'mode', 'captureMode', 'multiCapture', 'profileId', 'webhookUrlOverride', 'logLevel', 'testKey', 'liveKey',
    ];

    public function __construct(
        public string $expectedVersion,
        public ?string $mode = null,
        public ?string $captureMode = null,
        public ?bool $multiCapture = null,
        public ?string $profileId = null,
        public ?string $webhookUrlOverride = null,
        public ?string $logLevel = null,
        #[SensitiveParameter] public ?string $testKey = null,
        #[SensitiveParameter] public ?string $liveKey = null,
    ) {
    }

    /**
     * The new key of a mode, or null when this change leaves it alone.
     */
    public function keyFor(string $mode): ?string
    {
        $key = $mode === MollieDefinitions::MODE_LIVE ? $this->liveKey : $this->testKey;

        return $key === null || $key === '' ? null : $key;
    }

    public function writesKey(): bool
    {
        return $this->firstKeyField() !== null;
    }

    /**
     * The first API key this change carries (non-empty), test before live; null when it carries none. A field name,
     * never a value.
     */
    public function firstKeyField(): ?string
    {
        if ($this->keyFor(MollieDefinitions::MODE_TEST) !== null) {
            return 'testKey';
        }

        return $this->keyFor(MollieDefinitions::MODE_LIVE) !== null ? 'liveKey' : null;
    }

    /**
     * Names of the fields this change carries; never a value (the audit and logs use this).
     *
     * @return list<string>
     */
    public function fieldNames(): array
    {
        $names = [];
        foreach (self::FIELDS as $field) {
            $given = match ($field) {
                'testKey' => $this->keyFor(MollieDefinitions::MODE_TEST) !== null,
                'liveKey' => $this->keyFor(MollieDefinitions::MODE_LIVE) !== null,
                default => $this->$field !== null,
            };
            if ($given) {
                $names[] = $field;
            }
        }

        return $names;
    }
}
