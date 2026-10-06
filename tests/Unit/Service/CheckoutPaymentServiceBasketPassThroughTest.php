<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAddressDto;
use OxidEsales\Payments\Mollie\Service\CheckoutPaymentService;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieOrderDataProviderInterface;
use OxidEsales\Payments\Mollie\Service\MollieWebhookUrlProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GRAPH-QL / MS1 — a pay-later method needs the address and the lines of the
 * basket being paid; the headless checkout has no session, so the basket from
 * the context is handed through to the order data provider.
 */
final class CheckoutPaymentServiceBasketPassThroughTest extends TestCase
{
    public function testTheGivenBasketReachesTheOrderDataProviderForAPayLaterMethod(): void
    {
        $basket = $this->createMock(Basket::class);
        $address = new MollieAddressDto('Ada', 'Lovelace', 'ada@example.com', 'Analytical Way 1', '10115', 'Berlin', 'DE');
        $orderData = $this->createMock(MollieOrderDataProviderInterface::class);
        $orderData->expects($this->once())->method('billingAddress')->with($this->identicalTo($basket))->willReturn($address);
        $orderData->expects($this->once())->method('lines')->with('EUR', 10.0, $this->identicalTo($basket))->willReturn([]);

        $request = $this->service($orderData)->buildCreatePaymentRequest(
            $this->contract(),
            'klarna',
            'https://app.example.com/return',
            null,
            $basket
        );

        self::assertSame('klarna', $request->method);
        self::assertSame($address, $request->billingAddress);
    }

    public function testWithoutABasketTheProviderFallsBackToItsOwnSource(): void
    {
        $orderData = $this->createMock(MollieOrderDataProviderInterface::class);
        $orderData->expects($this->once())->method('billingAddress')->with(null)->willReturn(null);

        $request = $this->service($orderData)->buildCreatePaymentRequest($this->contract(), 'klarna', 'https://shop.test/return');

        self::assertNull($request->method, 'no address: the pay-later method is dropped as before');
    }

    private function service(MollieOrderDataProviderInterface $orderData): CheckoutPaymentService
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getCaptureMode')->willReturn('automatic');
        $webhooks = $this->createMock(MollieWebhookUrlProviderInterface::class);
        $webhooks->method('getWebhookUrl')->willReturn('https://shop.test/index.php?cl=MollieWebhookController');

        return new CheckoutPaymentService($config, $orderData, $webhooks, new NullLogger());
    }

    private function contract(): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getAmount')->willReturn(10.0);
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getMetadata')->willReturn(null);

        return $contract;
    }
}
