<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\ModuleConfigurationService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        ?LoggerInterface $logger = null,
    ) {
        // Intentionally does NOT call parent::__construct — no DAO/container needed in unit tests.
        // The seam still has to be initialised: Sprint 11 Story 9 made the logger a required
        // dependency, so it is never implicitly a NullLogger in production any more.
        $this->initializeForTest(unreadable: false, logger: $logger ?? new NullLogger());
    }

    protected function readSetting(string $name): mixed
    {
        return $this->settings[$name] ?? '';
    }
}
