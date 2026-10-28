<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use Symfony\Component\String\UnicodeString;

final class InMemoryModuleSettings implements ModuleSettingServiceInterface
{
    /** @var list<string> */
    public array $saved = [];

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(public array $values = [])
    {
    }

    public function getInteger(string $name, string $moduleId): int
    {
        return (int) ($this->values[$name] ?? 0);
    }

    public function getFloat(string $name, string $moduleId): float
    {
        return (float) ($this->values[$name] ?? 0.0);
    }

    public function getString(string $name, string $moduleId): UnicodeString
    {
        return new UnicodeString((string) ($this->values[$name] ?? ''));
    }

    public function getBoolean(string $name, string $moduleId): bool
    {
        return (bool) ($this->values[$name] ?? false);
    }

    public function getCollection(string $name, string $moduleId): array
    {
        $value = $this->values[$name] ?? [];

        return is_array($value) ? $value : [];
    }

    public function saveInteger(string $name, int $value, string $moduleId): void
    {
        $this->store($name, $value);
    }

    public function saveFloat(string $name, float $value, string $moduleId): void
    {
        $this->store($name, $value);
    }

    public function saveString(string $name, string $value, string $moduleId): void
    {
        $this->store($name, $value);
    }

    public function saveBoolean(string $name, bool $value, string $moduleId): void
    {
        $this->store($name, $value);
    }

    public function saveCollection(string $name, array $value, string $moduleId): void
    {
        $this->store($name, $value);
    }

    public function exists(string $name, string $moduleId): bool
    {
        return array_key_exists($name, $this->values);
    }

    private function store(string $name, mixed $value): void
    {
        $this->values[$name] = $value;
        $this->saved[] = $name;
    }
}
