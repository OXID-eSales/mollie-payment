<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Gateway;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Payment\RefundGatewayInterface;
use OxidEsales\PaymentBase\Payment\RefundRequest;
use OxidEsales\PaymentBase\Payment\RefundResult;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest as MollieRefundRequest;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Gateway\GatewayRefusals;
use OxidEsales\Payments\Mollie\Gateway\GatewayTextRules;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Gateway\MollieRefundGateway;
use OxidEsales\Payments\Mollie\Service\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\UserDataValidatorInterface;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * VR-22 A6 - the refund port of payment-base's money runner, for Mollie.
 */
final class MollieRefundGatewayTest extends TestCase
{
    private MollieRefundAdapterInterface&MockObject $refunds;
    private MolliePaymentsAdapterInterface&MockObject $payments;
    private UserDataValidatorInterface&MockObject $validator;
    private GatewayRefusals $refusals;

    protected function setUp(): void
    {
        $this->refunds = $this->createMock(MollieRefundAdapterInterface::class);
        $this->payments = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->validator = $this->createMock(UserDataValidatorInterface::class);
        $this->validator->method('validateFieldMap')->willReturn([]);
        $this->refusals = $this->realRefusals();
    }

    public function testItIsTheRefundGatewayOfMollie(): void
    {
        self::assertInstanceOf(RefundGatewayInterface::class, $this->gateway());
        self::assertSame(MollieDefinitions::PROVIDER_NAME, $this->gateway()->providerName());
    }

    public function testItRefundsTheAmountTheRunnerDecidedOnWithReasonDescriptionAndKey(): void
    {
        $this->refunds->expects(self::once())->method('createRefund')->with(self::callback(
            static fn (MollieRefundRequest $request): bool => $request->paymentId === 'tr_1'
                && $request->amount->currency === 'EUR'
                && $request->amount->value === 25.0
                && $request->reason === 'duplicate'
                && $request->description === 'Order 7'
                && $request->idempotencyKey === 'idem-1'
        ))->willReturn($this->refund('re_1', 'refunded'));

        $result = $this->gateway()->refund($this->request(25.0));

        self::assertSame('re_1', $result->providerRefundId);
        self::assertSame(25.0, $result->amount);
        self::assertSame(RefundResult::SUCCEEDED, $result->status);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mollieRefundStatuses(): iterable
    {
        yield 'refunded' => ['refunded', RefundResult::SUCCEEDED];
        yield 'queued' => ['queued', RefundResult::PENDING];
        yield 'pending' => ['pending', RefundResult::PENDING];
        yield 'processing' => ['processing', RefundResult::PENDING];
        yield 'failed' => ['failed', RefundResult::FAILED];
        yield 'canceled' => ['canceled', RefundResult::FAILED];
    }

    #[DataProvider('mollieRefundStatuses')]
    public function testTheMollieStatusBecomesTheRunnersStatus(string $mollieStatus, string $expected): void
    {
        $this->refunds->method('createRefund')->willReturn($this->refund('re_1', $mollieStatus));

        self::assertSame($expected, $this->gateway()->refund($this->request(25.0))->status);
    }

    public function testMollieSayingNoIsARefusalWithoutMolliesText(): void
    {
        $this->refunds->method('createRefund')->willThrowException(new MollieAdapterException('secret detail'));
        $this->payments->method('getPayment')->willReturn($this->payment('paid'));

        try {
            $this->gateway()->refund($this->request(25.0));
            self::fail('A refusal was expected');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('translated:MOLLIE_ADMIN_REFUND_FAILED', $refusal->getMessage());
        }
    }

    public function testARefusalWhileTheCaptureIsStillSettlingSaysSo(): void
    {
        $this->refunds->method('createRefund')->willThrowException(new MollieAdapterException('not yet'));
        $this->payments->method('getPayment')->willReturn($this->payment('authorized'));

        $this->assertRefusedWith('capture_settling', fn () => $this->gateway()->refund($this->request(25.0)));
    }

    public function testARefusalIsStillARefusalWhenMollieCannotBeAskedWhyEither(): void
    {
        $this->refunds->method('createRefund')->willThrowException(new MollieAdapterException('no'));
        $this->payments->method('getPayment')->willThrowException(new MollieAdapterException('down'));

        $this->assertRefusedWith('refund_failed', fn () => $this->gateway()->refund($this->request(25.0)));
    }

    public function testATransportFailureIsNotAnswered(): void
    {
        $this->refunds->method('createRefund')->willThrowException(new RuntimeException('timeout'));

        $this->expectException(RuntimeException::class);

        $this->gateway()->refund($this->request(25.0));
    }

    public function testATextTheRulesRefuseNeverReachesMollie(): void
    {
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->expects(self::once())->method('validateFieldMap')
            ->with(['refundDescription' => 'Order 7'], FieldValidationFailure::KIND_ADMIN)
            ->willReturn(['refundDescription' => new FieldValidationFailure('refundDescription', 'admin', 'blockedCharacter', ';', null)]);
        $this->validator = $validator;
        $this->refunds->expects(self::never())->method('createRefund');

        $this->assertRefusedWith('blocked_character', fn () => $this->gateway()->refund($this->request(25.0)));
    }

    public function testAContractWithoutAMolliePaymentIsRefused(): void
    {
        $this->refunds->expects(self::never())->method('createRefund');

        $this->assertRefusedWith('no_payment', fn () => $this->gateway()->refund($this->request(25.0, paymentId: null)));
    }

    private function gateway(): MollieRefundGateway
    {
        return new MollieRefundGateway(
            $this->refunds,
            $this->payments,
            $this->refusals,
            new GatewayTextRules($this->validator, $this->refusals),
        );
    }

    private function assertRefusedWith(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('A refusal was expected');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame($code, $refusal->refusalCode());
        }
    }

    private function realRefusals(): GatewayRefusals
    {
        $translator = $this->createStub(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnCallback(static fn (string $key): string => 'translated:' . $key);
        $formatter = $this->createStub(MessageFormatterInterface::class);
        $formatter->method('format')->willReturnCallback(static fn (string $field, string $code): string => $field . ':' . $code);

        return new GatewayRefusals($translator, $formatter);
    }

    private function request(float $amount, ?string $paymentId = 'tr_1'): RefundRequest
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('c-1');
        $contract->method('getProviderOrderId')->willReturn($paymentId);

        return new RefundRequest($contract, $amount, 'EUR', 'idem-1', null, 'duplicate', 'Order 7');
    }

    private function refund(string $id, string $status): MollieRefundDto
    {
        return new MollieRefundDto($id, 'tr_1', MollieAmountDto::fromComponents('EUR', 25.0), $status);
    }

    private function payment(string $status): MolliePaymentDto
    {
        return new MolliePaymentDto('tr_1', $status, MollieAmountDto::fromComponents('EUR', 100.0));
    }
}
