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

    /**
     * Manual capture is a per-method decision made when the payment is created
     * (see CheckoutPaymentService), not a reason to hide methods: the list no
     * longer knows the shop's capture mode at all. A shop that captures cards
     * manually still sells via iDEAL, PayPal and bank transfer — those simply
     * settle immediately, exactly as Mollie's own hosted page does.
     */
    public function testList_OffersEveryMethodWhateverTheCaptureMode(): void
    {
        $this->methodsAdapter->method('listActiveMethods')->willReturn([
            new MollieMethodDto('creditcard', 'Card'),
            new MollieMethodDto('ideal', 'iDEAL'),
            new MollieMethodDto('paypal', 'PayPal'),
            new MollieMethodDto('klarna', 'Klarna'),
        ]);

        $result = $this->service->listActiveMethods('EUR');
        $ids = array_map(static fn (MollieMethodDto $m): string => $m->id, $result);

        self::assertSame(['creditcard', 'ideal', 'paypal', 'klarna'], $ids);
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
