<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Immutable outcome of a configuration validation pass.
 */
final readonly class ConfigurationValidationResult
{
    /**
     * @param list<string> $errors
     */
    private function __construct(
        public bool $valid,
        public array $errors,
    ) {
    }

    public static function valid(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<string> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, $errors);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
