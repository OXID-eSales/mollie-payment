<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Adapter\Dto\MethodsListRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieMethodDto;
use OxidEsales\Payments\Mollie\Adapter\MollieMethodsAdapterInterface;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\PaymentMethodListService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentMethodListService::class)]
final class PaymentMethodListServiceTest extends TestCase
{
    private MollieMethodsAdapterInterface&MockObject $methodsAdapter;
    private ModuleConfigurationServiceInterface&MockObject $config;
    private PaymentMethodListService $service;

    protected function setUp(): void
    {
        $this->methodsAdapter = $this->createMock(MollieMethodsAdapterInterface::class);
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);
        // Default: automatic capture (no capture filtering).
        $this->config->method('isManualCapture')->willReturn(false);
        $this->service = new PaymentMethodListService($this->methodsAdapter, $this->config);
    }

    public function testList_ManualCapture_FiltersOutCaptureIncompatibleMethods(): void
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('isManualCapture')->willReturn(true);
        $service = new PaymentMethodListService($this->methodsAdapter, $config);

        $this->methodsAdapter->method('listActiveMethods')->willReturn([
            new MollieMethodDto('creditcard', 'Card'),
            new MollieMethodDto('ideal', 'iDEAL'),
            new MollieMethodDto('paypal', 'PayPal'),
            new MollieMethodDto('klarna', 'Klarna'),
        ]);

        $result = $service->listActiveMethods('EUR');
        $ids = array_map(static fn (MollieMethodDto $m): string => $m->id, $result);

        // Card + Klarna support manual capture; iDEAL is instant (no capture), PayPal is
        // onboarding-gated (not in the manual-capture allowlist) — both dropped.
        self::assertSame(['creditcard', 'klarna'], $ids);
    }

    public function testList_DropsVoucherMethodsEvenUnderAutomaticCapture(): void
    {
        $this->methodsAdapter->method('listActiveMethods')->willReturn([
            new MollieMethodDto('creditcard', 'Card'),
            new MollieMethodDto('ideal', 'iDEAL'),
            new MollieMethodDto('voucher', 'Voucher'),
        ]);

        $result = $this->service->listActiveMethods('EUR');
        $ids = array_map(static fn (MollieMethodDto $m): string => $m->id, $result);

        // Voucher needs per-line categories we don't emit yet → always excluded.
        self::assertSame(['creditcard', 'ideal'], $ids);
    }

    public function testList_ReturnsEnabledMethodsForCurrencyAndCountry(): void
    {
        $methods = [new MollieMethodDto('ideal', 'iDEAL')];
        $this->methodsAdapter->expects(self::once())->method('listActiveMethods')
            ->with(self::callback(function (MethodsListRequest $request): bool {
                self::assertSame('NL', $request->billingCountry);
                return true;
            }))
            ->willReturn($methods);

        self::assertSame($methods, $this->service->listActiveMethods('EUR', 'NL'));
    }

    public function testList_UnsupportedCurrency_ReturnsEmptyListWithoutCallingAdapter(): void
    {
        $this->methodsAdapter->expects(self::never())->method('listActiveMethods');

        self::assertSame([], $this->service->listActiveMethods('USD'));
    }

    public function testList_CachesResultPerCurrencyAndCountryWithinTheRequest(): void
    {
        $methods = [new MollieMethodDto('creditcard', 'Credit card')];
        $this->methodsAdapter->expects(self::once())->method('listActiveMethods')->willReturn($methods);

        $first = $this->service->listActiveMethods('EUR', 'DE');
        $second = $this->service->listActiveMethods('EUR', 'DE');

        self::assertSame($first, $second);
    }

    public function testList_DifferentCountry_IsNotServedFromTheOtherCountrysCache(): void
    {
        $this->methodsAdapter->expects(self::exactly(2))->method('listActiveMethods')->willReturn([]);

        $this->service->listActiveMethods('EUR', 'DE');
        $this->service->listActiveMethods('EUR', 'NL');
    }
}
