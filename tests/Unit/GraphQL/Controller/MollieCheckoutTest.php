<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Controller;

use OxidEsales\GraphQL\Base\DataType\User;
use OxidEsales\GraphQL\Base\Service\Authentication;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCancelResult;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessReturnResult;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartRequest;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutCancelResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutReturnResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutStartResult;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\GraphQL\Controller\MollieCheckout;
use OxidEsales\Payments\Mollie\GraphQL\Exception\MollieCheckoutError;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheCodingMachine\GraphQLite\Types\ID;

/**
 * GRAPH-QL / MS4 — the three mutations are a thin shell over payment-base's
 * HeadlessCheckoutService: the JWT user is the buyer, the Mollie payment id
 * and the optional method hint travel with the start, the return needs no
 * provider parameters, and a refusal becomes a client-safe GraphQL error.
 */
final class MollieCheckoutTest extends TestCase
{
    private HeadlessCheckoutServiceInterface&MockObject $checkout;
    private Authentication&MockObject $authentication;

    protected function setUp(): void
    {
        $this->checkout = $this->createMock(HeadlessCheckoutServiceInterface::class);
        $this->authentication = $this->createMock(Authentication::class);
        $this->authentication->method('getUser')->willReturn(new User('user-1'));
    }

    public function testStartHandsTheJwtUserThePaymentIdAndTheMethodHintToTheHeadlessService(): void
    {
        $this->checkout->expects($this->once())->method('start')
            ->with($this->callback(function (HeadlessStartRequest $r): bool {
                self::assertSame('user-1', $r->userId);
                self::assertSame('ub-1', $r->basketId);
                self::assertTrue($r->confirmTermsAndConditions);
                self::assertSame('https://app.example.com/return', $r->returnUrl);
                self::assertSame('https://app.example.com/cancel', $r->cancelUrl);
                self::assertSame('hosted', $r->uiMode);
                self::assertSame(MollieDefinitions::PAYMENT_ID, $r->paymentId, 'the mutation names its own payment');
                self::assertSame(['mollieMethod' => 'ideal'], $r->providerOptions);

                return true;
            }))
            ->willReturn(new HeadlessStartResult('ctr-1', 'tok-1', 'mollie', '1001', 'https://www.mollie.com/checkout/tr_1', null, 'redirect'));

        $result = $this->controller()->mollieCheckoutStart(
            new ID('ub-1'),
            true,
            'https://app.example.com/return',
            'https://app.example.com/cancel',
            'ideal'
        );

        self::assertInstanceOf(CheckoutStartResult::class, $result);
        self::assertSame('ctr-1', $result->contractId());
        self::assertSame('tok-1', $result->contractToken());
        self::assertSame('https://www.mollie.com/checkout/tr_1', $result->redirectUrl());
        self::assertSame('redirect', $result->renderMode());
    }

    public function testStartWithoutAMethodPassesNoProviderOptions(): void
    {
        $this->checkout->method('start')
            ->with($this->callback(fn(HeadlessStartRequest $r): bool => $r->providerOptions === [] && $r->cancelUrl === null))
            ->willReturn(new HeadlessStartResult('ctr-1', 'tok-1', 'mollie', null, 'https://www.mollie.com/checkout/tr_1', null, 'redirect'));

        self::assertSame(
            'https://www.mollie.com/checkout/tr_1',
            $this->controller()->mollieCheckoutStart(new ID('ub-1'), true, 'https://app.example.com/return')->redirectUrl()
        );
    }

    public function testARefusedStartBecomesAClientSafeErrorWithTheStableCode(): void
    {
        $this->checkout->method('start')->willThrowException(
            new HeadlessCheckoutException(HeadlessCheckoutException::PROVIDER_FAILED, 'Mollie refused', 'MOLLIE_UI_MODE_UNSUPPORTED')
        );

        try {
            $this->controller()->mollieCheckoutStart(new ID('ub-1'), true, 'https://app.example.com/return', null, null, 'embedded');
            self::fail('refusals surface as GraphQL errors');
        } catch (MollieCheckoutError $e) {
            self::assertSame('Mollie refused', $e->getMessage());
            self::assertSame('requesterror', $e->getCategory());
            self::assertSame(HeadlessCheckoutException::PROVIDER_FAILED, $e->getExtensions()['errorCode']);
            self::assertSame('MOLLIE_UI_MODE_UNSUPPORTED', $e->getExtensions()['providerCode']);
            self::assertTrue($e->isClientSafe());
        }
    }

    public function testReturnNeedsNoProviderParameters(): void
    {
        $this->checkout->expects($this->once())->method('return')
            ->with('ctr-1', 'tok-1', [])
            ->willReturn(new HeadlessReturnResult('committed', 'order-1', '1001', 'committed'));

        $result = $this->controller()->mollieCheckoutReturn('ctr-1', 'tok-1');

        self::assertInstanceOf(CheckoutReturnResult::class, $result);
        self::assertSame('committed', $result->status());
        self::assertSame('order-1', $result->orderId());
    }

    public function testCancelDelegatesAndAnswersTheState(): void
    {
        $this->checkout->expects($this->once())->method('cancel')->with('ctr-1', 'tok-1')
            ->willReturn(new HeadlessCancelResult(true, 'ctr-1', 'cancelled'));

        $result = $this->controller()->mollieCheckoutCancel('ctr-1', 'tok-1');

        self::assertInstanceOf(CheckoutCancelResult::class, $result);
        self::assertTrue($result->cancelled());
        self::assertSame('cancelled', $result->contractState());
    }

    public function testAnInvalidTokenOnReturnIsAClientSafeError(): void
    {
        $this->checkout->method('return')->willThrowException(
            new HeadlessCheckoutException(HeadlessCheckoutException::INVALID_TOKEN, 'bad token')
        );

        $this->expectException(MollieCheckoutError::class);
        $this->controller()->mollieCheckoutReturn('ctr-1', 'nope');
    }

    public function testWithoutGraphqlBaseTheMutationExplainsItself(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('graphql-base is not installed');

        (new MollieCheckout($this->checkout, null))->mollieCheckoutStart(new ID('ub-1'), true, 'https://app.example.com/return');
    }

    private function controller(): MollieCheckout
    {
        return new MollieCheckout($this->checkout, $this->authentication);
    }
}
