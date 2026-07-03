<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Core;

use OxidEsales\Payments\Mollie\Core\ViewConfig;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\PaymentMethodListServiceInterface;

/**
 * Test double bypassing OXID's Registry/DI-bound seams — mirrors
 * `TestableModuleConfigurationService`.
 */
final class TestableMollieViewConfig extends ViewConfig
{
    public function __construct(
        private readonly PaymentMethodListServiceInterface $service,
        private readonly string $currency,
        private readonly ?ModuleConfigurationServiceInterface $configService = null,
    ) {
        // Intentionally does NOT call parent::__construct — no OXID bootstrap needed in unit tests.
    }

    protected function mollieActiveCurrency(): string
    {
        return $this->currency;
    }

    protected function mollieMethodListService(): PaymentMethodListServiceInterface
    {
        return $this->service;
    }

    protected function mollieConfigService(): ?ModuleConfigurationServiceInterface
    {
        return $this->configService;
    }
}
