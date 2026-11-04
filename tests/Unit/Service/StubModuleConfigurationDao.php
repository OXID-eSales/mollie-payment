<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Setting\Setting;
use RuntimeException;

/**
 * The stub DAO payment-base's configuration base class is built with in unit tests: it serves canned
 * settings, or fails the way a broken shop would (the configuration is unreadable).
 */
final class StubModuleConfigurationDao implements ModuleConfigurationDaoInterface
{
    /**
     * @param array<string, mixed>|null $settings null: the DAO throws (unreadable configuration)
     */
    public function __construct(private readonly ?array $settings)
    {
    }

    public function get(string $moduleId, int $shopId): ModuleConfiguration
    {
        if ($this->settings === null) {
            throw new RuntimeException('configuration row is gone');
        }

        $configuration = new ModuleConfiguration();
        foreach ($this->settings as $name => $value) {
            $setting = new Setting();
            $setting->setName($name);
            $setting->setValue($value);
            $configuration->addModuleSetting($setting);
        }

        return $configuration;
    }

    public function save(ModuleConfiguration $moduleConfiguration, int $shopId): void
    {
    }

    public function delete(string $moduleId, int $shopId): void
    {
    }

    public function getAll(int $shopId): array
    {
        return [];
    }

    public function deleteAll(int $shopId): void
    {
    }

    public function exists(string $moduleId, int $shopId): bool
    {
        return $this->settings !== null;
    }
}
