<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\EventSystem\Handler;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Handler\MollieCheckoutSessionHandler;
use OxidEsales\Payments\Mollie\Service\CheckoutPaymentServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieRedirectUrlValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * GRAPH-QL / MS1 — a headless contract returns to the client's URL, not to the
 * shop's checkoutReturn, and the basket being paid travels with the context to
 * the payment request (pay-later lines and address without a session).
 */
final class MollieCheckoutSessionHandlerHeadlessTest extends TestCase
{
    private CheckoutPaymentServiceInterface&MockObject $checkoutPaymentService;
    private TokenServiceInterface&MockObject $tokenService;
    private MollieCheckoutSessionHandler $handler;

    protected function setUp(): void
    {
        $this->checkoutPaymentService = $this->createMock(CheckoutPaymentServiceInterface::class);
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->method('createPayment')->willReturn(new MolliePaymentDto(
            id: 'tr_abc123',
            status: 'open',
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            checkoutUrl: 'https://www.mollie.com/checkout/tr_abc123',
        ));
        $this->tokenService = $this->createMock(TokenServiceInterface::class);
        $this->tokenService->method('generateToken')->willReturn('tok_contract-1');
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopUrl')->willReturn('https://shop.test/');

        $this->handler = new MollieCheckoutSessionHandler(
            $this->checkoutPaymentService,
            $adapter,
            $this->createMock(ContractRepositoryInterface::class),
            $this->tokenService,
            $shopAdapter,
            new MollieRedirectUrlValidator(),
        );
    }

    public function testAHeadlessContractReturnsToTheClientUrlWithTheContractIdAndPaysTheContextBasket(): void
    {
        $basket = $this->createMock(Basket::class);
        $this->checkoutPaymentService->expects($this->once())->method('buildCreatePaymentRequest')
            ->with(
                $this->anything(),
                'ideal',
                'https://app.example.com/return?landing=1&contract_id=contract-1',
                null,
                $this->identicalTo($basket)
            )
            ->willReturn($this->request('https://app.example.com/return?landing=1&contract_id=contract-1'));
        $this->tokenService->expects($this->never())->method('generateToken');

        $context = new EventContext([
            'headless' => true,
            'returnUrl' => 'https://app.example.com/return?landing=1',
            'selectedMethod' => 'ideal',
            'basket' => $basket,
        ]);
        $context->setContract($this->contract());

        $this->handler->handle(new MollieCheckoutSessionRequestEvent($context));

        self::assertSame('https://www.mollie.com/checkout/tr_abc123', $context->get('checkoutUrl'));
    }

    public function testTheTwigPathStillReturnsToTheShopWithTheContractToken(): void
    {
        $expected = 'https://shop.test/index.php?cl=' . MollieDefinitions::ORDER_CONTROLLER_ID
            . '&fnc=checkoutReturn&contract_id=contract-1&contract_token=tok_contract-1';
        $this->checkoutPaymentService->expects($this->once())->method('buildCreatePaymentRequest')
            ->with($this->anything(), null, $expected, null, null)
            ->willReturn($this->request($expected));

        $context = new EventContext(['returnUrl' => 'https://app.example.com/return']); // no headless flag: ignored
        $context->setContract($this->contract());

        $this->handler->handle(new MollieCheckoutSessionRequestEvent($context));
    }

    private function request(string $redirectUrl): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            description: '2026-00001',
            redirectUrl: $redirectUrl,
        );
    }

    private function contract(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getAmount')->willReturn(10.0);
        $contract->method('getCurrency')->willReturn('EUR');

        return $contract;
    }
}
