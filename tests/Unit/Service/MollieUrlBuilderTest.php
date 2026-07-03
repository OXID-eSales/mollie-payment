<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieUrlBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieUrlBuilder::class)]
final class MollieUrlBuilderTest extends TestCase
{
    private ModuleConfigurationServiceInterface&MockObject $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);
    }

    public function testPaymentUrl_InTestMode_UsesTestModeDashboard(): void
    {
        $this->config->method('isTestMode')->willReturn(true);
        $builder = new MollieUrlBuilder($this->config);

        self::assertSame(
            'https://my.mollie.com/dashboard/test-mode/payments/tr_abc',
            $builder->paymentUrl('tr_abc'),
        );
    }

    public function testPaymentUrl_InLiveMode_UsesLiveDashboard(): void
    {
        $this->config->method('isTestMode')->willReturn(false);
        $builder = new MollieUrlBuilder($this->config);

        self::assertSame(
            'https://my.mollie.com/dashboard/payments/tr_abc',
            $builder->paymentUrl('tr_abc'),
        );
    }

    public function testPaymentUrl_EncodesThePaymentId(): void
    {
        $this->config->method('isTestMode')->willReturn(false);
        $builder = new MollieUrlBuilder($this->config);

        self::assertSame(
            'https://my.mollie.com/dashboard/payments/tr%2Fabc',
            $builder->paymentUrl('tr/abc'),
        );
    }
}
