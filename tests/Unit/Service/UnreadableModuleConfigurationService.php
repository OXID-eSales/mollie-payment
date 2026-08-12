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
 * Test double standing in for the production failure this sprint cares about (F4): the module
 * configuration could not be read at all — the DAO threw in the constructor, the shop id was wrong,
 * the row is missing.
 *
 * It reports itself unreadable rather than stubbing `readSetting()`, because the distinction between
 * "unreadable" and "unset" is exactly what {@see ModuleConfigurationService} now has to keep.
 */
final class UnreadableModuleConfigurationService extends ModuleConfigurationService
{
    public function __construct(?LoggerInterface $logger = null)
    {
        // Intentionally does NOT call parent::__construct — no DAO/container needed in unit tests.
        $this->initializeForTest(unreadable: true, logger: $logger ?? new NullLogger());
    }
}
