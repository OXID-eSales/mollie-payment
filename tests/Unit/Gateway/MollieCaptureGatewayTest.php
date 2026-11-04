<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Gateway;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Payment\CaptureGatewayInterface;
use OxidEsales\PaymentBase\Payment\CaptureRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\CaptureRequest as MollieCaptureRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MollieCaptureAdapterInterface;
use OxidEsales\Payments\Mollie\Gateway\GatewayRefusals;
use OxidEsales\PaymentBase\Validation\User\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\PaymentBase\Validation\User\UserDataValidatorInterface;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Gateway\GatewayTextRules;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Gateway\MollieCaptureGateway;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * VR-22 A6 - the capture port of payment-base's money runner, for Mollie. The runner owns policy, idempotency, the
 * lock and the booking; the gateway only talks to Mollie and says no in sentences the admin can read.
 */
final class MollieCaptureGatewayTest extends TestCase
{
    private MollieCaptureAdapterInterface&MockObject $captures;
    private ModuleConfigurationServiceInterface&MockObject $config;
    private UserDataValidatorInterface&MockObject $validator;
    private GatewayRefusals $refusals;

    protected function setUp(): void
    {
        $this->captures = $this->createMock(MollieCaptureAdapterInterface::class);
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $this->validator = $this->createMock(UserDataValidatorInterface::class);
        $this->validator->method('validateFieldMap')->willReturn([]);
        $this->refusals = $this->realRefusals();
    }

    public function testItIsTheCaptureGatewayOfMollie(): void
    {
        self::assertInstanceOf(CaptureGatewayInterface::class, $this->gateway());
        self::assertSame(MollieDefinitions::PROVIDER_NAME, $this->gateway()->providerName());
    }

    public function testItCapturesTheAmountTheRunnerDecidedOnWithTheRunnersIdempotencyKey(): void
    {
        $this->captures->expects(self::once())->method('createCapture')->with(self::callback(
            static fn (MollieCaptureRequest $request): bool => $request->paymentId === 'tr_1'
                && $request->amount?->currency === 'EUR'
                && $request->amount->value === 40.0
                && $request->idempotencyKey === 'idem-1'
        ))->willReturn(new MollieCaptureDto('cpt_1', 'tr_1', MollieAmountDto::fromComponents('EUR', 40.0), 'succeeded'));

        $result = $this->gateway()->capture($this->request(40.0));

        self::assertSame('cpt_1', $result->providerCaptureId);
        self::assertSame(40.0, $result->amount);
    }

    public function testASecondPartialCaptureIsRefusedWhereMollieCapturesOnce(): void
    {
        $this->config->method('isMultiCaptureEnabled')->willReturn(false);
        $this->captures->expects(self::never())->method('createCapture');

        $this->assertRefusedWith('capture_final', fn () => $this->gateway()->capture($this->request(20.0, captured: 40.0)));
    }

    public function testASecondPartialCaptureGoesToMollieOnAMultiCaptureAccount(): void
    {
        $this->config->method('isMultiCaptureEnabled')->willReturn(true);
        $this->captures->expects(self::once())->method('createCapture')
            ->willReturn(new MollieCaptureDto('cpt_2', 'tr_1', MollieAmountDto::fromComponents('EUR', 20.0), 'succeeded'));

        self::assertSame('cpt_2', $this->gateway()->capture($this->request(20.0, captured: 40.0))->providerCaptureId);
    }

    public function testMollieSayingNoIsARefusalWithoutMolliesText(): void
    {
        $this->captures->method('createCapture')->willThrowException(new MollieAdapterException('tr_1 amount 99.00 key test_secret'));

        try {
            $this->gateway()->capture($this->request(40.0));
            self::fail('A refusal was expected');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('translated:MOLLIE_ADMIN_CAPTURE_FAILED', $refusal->getMessage());
        }
    }

    public function testATextTheRulesRefuseNeverReachesMollie(): void
    {
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->expects(self::once())->method('validateFieldMap')
            ->with('oe_payments_mollie', ['captureReason' => 'bad;'], FieldValidationFailure::KIND_ADMIN)
            ->willReturn(['captureReason' => new FieldValidationFailure('captureReason', 'admin', 'blockedCharacter', ';', null)]);
        $this->validator = $validator;
        $this->captures->expects(self::never())->method('createCapture');

        $this->assertRefusedWith('blocked_character', fn () => $this->gateway()->capture($this->request(40.0, reason: 'bad;')));
    }

    public function testAContractWithoutAMolliePaymentIsRefused(): void
    {
        $this->captures->expects(self::never())->method('createCapture');

        $this->assertRefusedWith('no_payment', fn () => $this->gateway()->capture($this->request(40.0, paymentId: null)));
    }

    private function gateway(): MollieCaptureGateway
    {
        return new MollieCaptureGateway(
            $this->captures,
            $this->config,
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

    private function request(float $amount, float $captured = 0.0, ?string $paymentId = 'tr_1', ?string $reason = null): CaptureRequest
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('c-1');
        $contract->method('getProviderOrderId')->willReturn($paymentId);
        $contract->method('getCapturedAmount')->willReturn($captured > 0.0 ? $captured : null);

        return new CaptureRequest($contract, $amount, 'EUR', 'idem-1', $reason);
    }
}
