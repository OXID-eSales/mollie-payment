<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Handler\MollieCheckoutSessionHandler;
use OxidEsales\Payments\Mollie\Service\CheckoutPaymentServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieRedirectUrlValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieCheckoutSessionHandler::class)]
final class MollieCheckoutSessionHandlerTest extends TestCase
{
    public function testRegistersCheckoutSessionRequestEvent(): void
    {
        self::assertSame(
            MollieCheckoutSessionRequestEvent::class,
            MollieCheckoutSessionHandler::getHandledEventClass(),
        );
    }

    public function testGetPriorityReturns10(): void
    {
        self::assertSame(10, $this->handler()['handler']->getPriority());
    }

    public function testHandleCallsCreatePaymentWithAmountAndOrderNumber(): void
    {
        $contract = $this->contractStub();
        ['handler' => $handler, 'checkoutPaymentService' => $checkoutPaymentService, 'adapter' => $adapter]
            = $this->handler();

        $expectedRequest = new CreatePaymentRequest(
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            description: '2026-00001',
            redirectUrl: 'https://shop.test/index.php?cl=' . MollieDefinitions::ORDER_CONTROLLER_ID
                . '&fnc=checkoutReturn&contract_id=contract-1&contract_token=tok_contract-1',
        );
        $checkoutPaymentService->method('buildCreatePaymentRequest')->willReturn($expectedRequest);

        $adapter->expects(self::once())
            ->method('createPayment')
            ->with($expectedRequest)
            ->willReturn($this->paymentDto());

        $context = new EventContext();
        $context->setContract($contract);

        $handler->handle(new MollieCheckoutSessionRequestEvent($context));
    }

    /**
     * There is no storefront method selector: the module intentionally always builds the
     * create-payment request with `method = null` so Mollie's hosted checkout page offers every
     * method enabled in the merchant's Mollie dashboard.
     */
    public function testHandleSendsNullMethodRedirectUrlAndWebhookUrlViaCheckoutPaymentService(): void
    {
        $contract = $this->contractStub();
        ['handler' => $handler, 'checkoutPaymentService' => $checkoutPaymentService, 'adapter' => $adapter]
            = $this->handler();

        $checkoutPaymentService->expects(self::once())
            ->method('buildCreatePaymentRequest')
            ->with(
                $contract,
                null,
                self::stringContains('cl=' . MollieDefinitions::ORDER_CONTROLLER_ID . '&fnc=checkoutReturn'),
            )
            ->willReturn(new CreatePaymentRequest(
                amount: MollieAmountDto::fromComponents('EUR', 10.0),
                description: 'x',
                redirectUrl: 'https://shop.test/return',
                webhookUrl: 'https://shop.test/webhook',
            ));

        $adapter->method('createPayment')->willReturn($this->paymentDto());

        $context = new EventContext();
        $context->setContract($contract);

        $handler->handle(new MollieCheckoutSessionRequestEvent($context));
    }

    public function testHandleStoresMolliePaymentIdAndCheckoutUrlOnContract(): void
    {
        $contract = $this->contractStub();
        $contract->expects(self::once())
            ->method('setProvider')
            ->with(MollieDefinitions::PROVIDER_NAME, 'tr_abc123', 'https://www.mollie.com/checkout/tr_abc123');

        $metadata = [];
        $contract->method('setMetadata')->willReturnCallback(
            static function (string $key, mixed $value) use (&$metadata): void {
                $metadata[$key] = $value;
            },
        );

        ['handler' => $handler, 'checkoutPaymentService' => $checkoutPaymentService, 'adapter' => $adapter, 'repository' => $repository]
            = $this->handler();

        $checkoutPaymentService->method('buildCreatePaymentRequest')->willReturn(new CreatePaymentRequest(
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            description: 'x',
            redirectUrl: 'https://shop.test/return',
        ));
        $adapter->method('createPayment')->willReturn($this->paymentDto());

        $repository->expects(self::once())->method('save')->with($contract);

        $context = new EventContext();
        $context->setContract($contract);

        $handler->handle(new MollieCheckoutSessionRequestEvent($context));

        self::assertSame('tr_abc123', $metadata['mollie_payment_id'] ?? null);
        self::assertSame('https://www.mollie.com/checkout/tr_abc123', $metadata['mollie_checkout_url'] ?? null);
        self::assertSame('https://www.mollie.com/checkout/tr_abc123', $context->get('checkoutUrl'));
    }

    /**
     * F14 (open redirect) parity: an untrusted checkoutUrl host must never reach the browser
     * redirect. Handled the same way an adapter exception is — fail the contract, leave
     * `checkoutUrl` unset on the context so PaymentController shows the generic "unavailable"
     * error instead of redirecting anywhere.
     */
    public function testHandleFailsContractWhenCheckoutUrlHostIsNotAllowed(): void
    {
        $contract = $this->contractStub();
        $contract->expects(self::once())->method('fail')->with(self::stringContains('untrusted_redirect_host'));
        $contract->expects(self::never())->method('setProvider');

        ['handler' => $handler, 'checkoutPaymentService' => $checkoutPaymentService, 'adapter' => $adapter, 'repository' => $repository]
            = $this->handler();

        $checkoutPaymentService->method('buildCreatePaymentRequest')->willReturn(new CreatePaymentRequest(
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            description: 'x',
            redirectUrl: 'https://shop.test/return',
        ));
        $adapter->method('createPayment')->willReturn(new MolliePaymentDto(
            id: 'tr_evil',
            status: 'open',
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            checkoutUrl: 'https://attacker.example/tr_evil',
        ));

        $repository->expects(self::once())->method('save')->with($contract);

        $context = new EventContext();
        $context->setContract($contract);

        $handler->handle(new MollieCheckoutSessionRequestEvent($context));

        self::assertNull($context->get('checkoutUrl'));
    }

    public function testHandleOnAdapterExceptionFailsContract(): void
    {
        $contract = $this->contractStub();
        $contract->expects(self::once())
            ->method('fail')
            ->with(self::stringContains('boom'));
        $contract->expects(self::never())->method('setProvider');

        ['handler' => $handler, 'checkoutPaymentService' => $checkoutPaymentService, 'adapter' => $adapter, 'repository' => $repository]
            = $this->handler();

        $checkoutPaymentService->method('buildCreatePaymentRequest')->willReturn(new CreatePaymentRequest(
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            description: 'x',
            redirectUrl: 'https://shop.test/return',
        ));
        $adapter->method('createPayment')->willThrowException(new MollieAdapterException('boom'));

        $repository->expects(self::once())->method('save')->with($contract);

        $context = new EventContext();
        $context->setContract($contract);

        $handler->handle(new MollieCheckoutSessionRequestEvent($context));

        self::assertNull($context->get('checkoutUrl'));
    }

    public function testHandleWithCardTokenPassesCreditcardMethodAndTokenToService(): void
    {
        $contract = $this->contractStub();
        ['handler' => $handler, 'checkoutPaymentService' => $checkoutPaymentService, 'adapter' => $adapter]
            = $this->handler();

        $checkoutPaymentService->expects(self::once())
            ->method('buildCreatePaymentRequest')
            ->with($contract, 'creditcard', self::anything(), 'tkn_live_123')
            ->willReturn(new CreatePaymentRequest(
                amount: MollieAmountDto::fromComponents('EUR', 10.0),
                description: 'x',
                redirectUrl: 'https://shop.test/return',
                method: 'creditcard',
                cardToken: 'tkn_live_123',
            ));
        $adapter->method('createPayment')->willReturn($this->paymentDto());

        // Card selected inline: the mollieMethod radio value is "creditcard" and Components minted
        // the token.
        $context = new EventContext();
        $context->setContract($contract);
        $context->set('selectedMethod', 'creditcard');
        $context->set('cardToken', 'tkn_live_123');

        $handler->handle(new MollieCheckoutSessionRequestEvent($context));
    }

    /**
     * IFRAME-04: a non-card method selected inline (e.g. iDEAL) is passed straight through to the
     * create-payment request so Mollie's hosted page opens directly on that method.
     */
    public function testHandlePassesSelectedMethodToService(): void
    {
        $contract = $this->contractStub();
        ['handler' => $handler, 'checkoutPaymentService' => $checkoutPaymentService, 'adapter' => $adapter]
            = $this->handler();

        $checkoutPaymentService->expects(self::once())
            ->method('buildCreatePaymentRequest')
            ->with($contract, 'ideal', self::anything(), null)
            ->willReturn(new CreatePaymentRequest(
                amount: MollieAmountDto::fromComponents('EUR', 10.0),
                description: 'x',
                redirectUrl: 'https://shop.test/return',
                method: 'ideal',
            ));
        $adapter->method('createPayment')->willReturn($this->paymentDto());

        $context = new EventContext();
        $context->setContract($contract);
        $context->set('selectedMethod', 'ideal');

        $handler->handle(new MollieCheckoutSessionRequestEvent($context));
    }

    /**
     * IFRAME-04: an inline card that clears WITHOUT 3-D Secure yields no Mollie checkout URL.
     * The handler must then send the shopper to our own return leg (checkoutReturn) to finalize —
     * not treat the missing URL as a failure.
     */
    public function testHandleWithCardTokenAndNoThreeDsRedirectsToOwnReturnLeg(): void
    {
        $contract = $this->contractStub();
        ['handler' => $handler, 'checkoutPaymentService' => $checkoutPaymentService, 'adapter' => $adapter, 'repository' => $repository]
            = $this->handler();

        $checkoutPaymentService->method('buildCreatePaymentRequest')->willReturn(new CreatePaymentRequest(
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            description: 'x',
            redirectUrl: 'https://shop.test/return',
            method: 'creditcard',
            cardToken: 'tkn_live_123',
        ));
        $adapter->method('createPayment')->willReturn(new MolliePaymentDto(
            id: 'tr_card1',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            checkoutUrl: null,
        ));
        $repository->expects(self::once())->method('save')->with($contract);

        $context = new EventContext();
        $context->setContract($contract);
        $context->set('cardToken', 'tkn_live_123');

        $handler->handle(new MollieCheckoutSessionRequestEvent($context));

        $destination = $context->get('checkoutUrl');
        self::assertIsString($destination);
        self::assertStringContainsString(
            'cl=' . MollieDefinitions::ORDER_CONTROLLER_ID . '&fnc=checkoutReturn',
            $destination,
        );
    }

    /**
     * The classic redirect flow (no card token) with a payment that has no checkout URL is a
     * genuine failure — the shopper must not be sent anywhere.
     */
    public function testHandleWithoutCardTokenAndNoCheckoutUrlFailsContract(): void
    {
        $contract = $this->contractStub();
        $contract->expects(self::once())->method('fail');
        $contract->expects(self::never())->method('setProvider');

        ['handler' => $handler, 'checkoutPaymentService' => $checkoutPaymentService, 'adapter' => $adapter]
            = $this->handler();

        $checkoutPaymentService->method('buildCreatePaymentRequest')->willReturn(new CreatePaymentRequest(
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            description: 'x',
            redirectUrl: 'https://shop.test/return',
        ));
        $adapter->method('createPayment')->willReturn(new MolliePaymentDto(
            id: 'tr_nourl',
            status: 'open',
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            checkoutUrl: null,
        ));

        $context = new EventContext();
        $context->setContract($contract);

        $handler->handle(new MollieCheckoutSessionRequestEvent($context));

        self::assertNull($context->get('checkoutUrl'));
    }

    public function testHandleThrowsWhenNoContractInContext(): void
    {
        ['handler' => $handler] = $this->handler();

        $this->expectException(\RuntimeException::class);

        $handler->handle(new MollieCheckoutSessionRequestEvent(new EventContext()));
    }

    /**
     * @return array{
     *     handler: MollieCheckoutSessionHandler,
     *     checkoutPaymentService: CheckoutPaymentServiceInterface&\PHPUnit\Framework\MockObject\MockObject,
     *     adapter: MolliePaymentsAdapterInterface&\PHPUnit\Framework\MockObject\MockObject,
     *     repository: ContractRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject,
     * }
     */
    private function handler(): array
    {
        $checkoutPaymentService = $this->createMock(CheckoutPaymentServiceInterface::class);
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $repository = $this->createMock(ContractRepositoryInterface::class);

        $tokenService = $this->createMock(TokenServiceInterface::class);
        $tokenService->method('generateToken')->willReturnCallback(
            static fn (string $contractId): string => 'tok_' . $contractId,
        );

        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopUrl')->willReturn('https://shop.test/');

        $handler = new MollieCheckoutSessionHandler(
            $checkoutPaymentService,
            $adapter,
            $repository,
            $tokenService,
            $shopAdapter,
            new MollieRedirectUrlValidator(),
        );

        return [
            'handler' => $handler,
            'checkoutPaymentService' => $checkoutPaymentService,
            'adapter' => $adapter,
            'repository' => $repository,
        ];
    }

    private function contractStub(): PaymentContractInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getAmount')->willReturn(10.0);
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getMetadata')->willReturnCallback(
            static fn (string $key): mixed => $key === 'order_number' ? '2026-00001' : null,
        );

        return $contract;
    }

    private function paymentDto(): MolliePaymentDto
    {
        return new MolliePaymentDto(
            id: 'tr_abc123',
            status: 'open',
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            checkoutUrl: 'https://www.mollie.com/checkout/tr_abc123',
        );
    }
}
