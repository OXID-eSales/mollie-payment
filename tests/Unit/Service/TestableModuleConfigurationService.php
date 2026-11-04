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
 * The real {@see ModuleConfigurationService} fed canned settings through a stub DAO (payment-base's
 * base class takes the DAO, so no seam in production code is needed).
 */
final class TestableModuleConfigurationService extends ModuleConfigurationService
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings, ?LoggerInterface $logger = null)
    {
        parent::__construct(StubShopContext::create(), new StubModuleConfigurationDao($settings), $logger ?? new NullLogger());
    }
}
