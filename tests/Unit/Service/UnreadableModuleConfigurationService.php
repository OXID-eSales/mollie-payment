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
 * Stands in for the production failure F4 cares about: the module configuration could not be read at
 * all - the DAO throws in the constructor (wrong shop id, row missing).
 *
 * It is unreadable rather than unset, because that distinction is exactly what
 * {@see ModuleConfigurationService} has to keep.
 */
final class UnreadableModuleConfigurationService extends ModuleConfigurationService
{
    public function __construct(?LoggerInterface $logger = null)
    {
        parent::__construct(StubShopContext::create(), new StubModuleConfigurationDao(null), $logger ?? new NullLogger());
    }
}
