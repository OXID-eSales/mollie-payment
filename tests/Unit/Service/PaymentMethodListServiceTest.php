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
use OxidEsales\Payments\Mollie\Service\PaymentMethodListService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentMethodListService::class)]
final class PaymentMethodListServiceTest extends TestCase
{
    private MollieMethodsAdapterInterface&MockObject $methodsAdapter;
    private PaymentMethodListService $service;

    protected function setUp(): void
    {
        $this->methodsAdapter = $this->createMock(MollieMethodsAdapterInterface::class);
        $this->service = new PaymentMethodListService($this->methodsAdapter);
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
