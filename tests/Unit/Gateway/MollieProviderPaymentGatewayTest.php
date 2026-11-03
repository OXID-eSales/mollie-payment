<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Gateway;

use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGatewayInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentState;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Gateway\MollieProviderPaymentGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * VR-23 T1/T2 - what payment-base asks Mollie about a payment by its own id: what it holds, and how to give it back.
 */
final class MollieProviderPaymentGatewayTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $payments;
    private MollieRefundAdapterInterface&MockObject $refunds;

    protected function setUp(): void
    {
        $this->payments = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->refunds = $this->createMock(MollieRefundAdapterInterface::class);
    }

    public function testItIsTheProviderPaymentGatewayOfMollie(): void
    {
        self::assertInstanceOf(ProviderPaymentGatewayInterface::class, $this->gateway());
        self::assertSame(MollieDefinitions::PROVIDER_NAME, $this->gateway()->provider());
    }

    /**
     * @return iterable<string, array{string, ProviderPaymentState}>
     */
    public static function statuses(): iterable
    {
        yield 'paid holds captured money' => ['paid', ProviderPaymentState::Paid];
        yield 'authorized holds a reservation' => ['authorized', ProviderPaymentState::Authorized];
        yield 'open holds nothing' => ['open', ProviderPaymentState::NoMoney];
        yield 'pending holds nothing yet' => ['pending', ProviderPaymentState::NoMoney];
        yield 'canceled' => ['canceled', ProviderPaymentState::NoMoney];
        yield 'expired' => ['expired', ProviderPaymentState::NoMoney];
        yield 'failed' => ['failed', ProviderPaymentState::NoMoney];
    }

    #[DataProvider('statuses')]
    public function testStatusOfNamesWhatThePaymentHolds(string $mollieStatus, ProviderPaymentState $expected): void
    {
        $this->payments->method('getPayment')->with('tr_1')->willReturn($this->payment($mollieStatus));

        $status = $this->gateway()->statusOf('tr_1');

        self::assertSame($expected, $status->state);
        self::assertSame('tr_1', $status->providerPaymentId);
        self::assertSame(25.5, $status->amount);
        self::assertSame('EUR', $status->currency);
    }

    public function testRefundGivesBackTheWholePaymentUnderTheGivenKeyAndReturnsMolliesRefundId(): void
    {
        $this->payments->method('getPayment')->with('tr_1')->willReturn($this->payment('paid'));
        $this->refunds->expects(self::once())->method('createRefund')->with(self::callback(
            static fn (RefundRequest $request): bool => $request->paymentId === 'tr_1'
                && $request->amount->currency === 'EUR'
                && $request->amount->value === 25.5
                && $request->idempotencyKey === 'late:tr_1'
        ))->willReturn(new MollieRefundDto('re_1', 'tr_1', MollieAmountDto::fromComponents('EUR', 25.5), 'queued'));

        self::assertSame('re_1', $this->gateway()->refund('tr_1', 'late:tr_1'));
    }

    public function testReleaseGivesTheAuthorizationBack(): void
    {
        $this->payments->expects(self::once())->method('releaseAuthorization')->with('tr_1');

        $this->gateway()->release('tr_1', 'late:tr_1');
    }

    private function gateway(): MollieProviderPaymentGateway
    {
        return new MollieProviderPaymentGateway($this->payments, $this->refunds);
    }

    private function payment(string $status): MolliePaymentDto
    {
        return new MolliePaymentDto('tr_1', $status, MollieAmountDto::fromComponents('EUR', 25.5));
    }
}
