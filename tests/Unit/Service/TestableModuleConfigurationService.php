<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\ModuleConfigurationService;

/**
 * Test double that bypasses the OXID DAO wiring by overriding the protected readSetting() seam,
 * feeding canned settings instead. Mirrors PayPal's TestableModuleConfigurationService pattern.
 */
final class TestableModuleConfigurationService extends ModuleConfigurationService
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private readonly array $settings,
    ) {
        // Intentionally does NOT call parent::__construct — no DAO/container needed in unit tests.
    }

    protected function readSetting(string $name): mixed
    {
        return $this->settings[$name] ?? '';
    }
}
