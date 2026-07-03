<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\CaptureRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\CaptureNotSupportedException;
use OxidEsales\Payments\Mollie\Adapter\MollieCaptureAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Service\CaptureService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CaptureService::class)]
final class CaptureServiceTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $paymentsAdapter;
    private MollieCaptureAdapterInterface&MockObject $captureAdapter;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private CaptureService $service;

    protected function setUp(): void
    {
        $this->paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->captureAdapter = $this->createMock(MollieCaptureAdapterInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);

        $this->service = new CaptureService(
            $this->paymentsAdapter,
            $this->captureAdapter,
            $this->contractRepository,
        );
    }

    public function testCapture_AuthorizedPayment_CallsCreateCapture_AdvancesContract(): void
    {
        $contract = $this->authorizedContract('tr_123');
        $this->paymentsAdapter->method('getPayment')->with('tr_123')->willReturn(
            $this->payment('tr_123', 100.0, 100.0),
        );

        $this->captureAdapter->expects(self::once())
            ->method('createCapture')
            ->with(self::callback(function (CaptureRequest $request): bool {
                self::assertSame('tr_123', $request->paymentId);
                self::assertNull($request->amount);
                return true;
            }))
            ->willReturn($this->captureDto('cp_1', 'tr_123', 100.0));

        $contract->expects(self::once())->method('setCapturedAmount')->with(100.0);
        $contract->expects(self::once())->method('setCapturedAt');
        $contract->expects(self::once())->method('captureAuthorization');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);

        $dto = $this->service->capture($contract);

        self::assertSame('cp_1', $dto->id);
    }

    public function testCapture_Partial_ReleasesRemainder(): void
    {
        $contract = $this->authorizedContract('tr_123');
        $this->paymentsAdapter->method('getPayment')->willReturn(
            $this->payment('tr_123', 100.0, 100.0),
        );
        $this->captureAdapter->expects(self::once())
            ->method('createCapture')
            ->with(self::callback(function (CaptureRequest $request): bool {
                self::assertNotNull($request->amount);
                self::assertSame(40.0, $request->amount->value);
                return true;
            }))
            ->willReturn($this->captureDto('cp_2', 'tr_123', 40.0));

        $contract->expects(self::once())->method('captureAuthorization');

        $dto = $this->service->capture($contract, 40.0);

        self::assertSame(40.0, $dto->amount->value);
    }

    public function testCapture_OnAutoCaptureMethod_ThrowsCaptureNotSupported(): void
    {
        $contract = $this->authorizedContract('tr_123');
        $this->paymentsAdapter->method('getPayment')->willReturn(
            $this->payment('tr_123', 100.0, 100.0),
        );
        $this->captureAdapter->method('createCapture')->willThrowException(
            new CaptureNotSupportedException('Payment tr_123 is not authorized'),
        );

        $contract->expects(self::never())->method('captureAuthorization');

        $this->expectException(CaptureNotSupportedException::class);

        $this->service->capture($contract);
    }

    public function testCapture_ExceedingAuthorized_Rejected(): void
    {
        $contract = $this->authorizedContract('tr_123');
        $this->paymentsAdapter->method('getPayment')->willReturn(
            $this->payment('tr_123', 100.0, 100.0),
        );

        $this->captureAdapter->expects(self::never())->method('createCapture');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->capture($contract, 150.0);
    }

    public function testCapture_WhenAlreadyCaptured_Rejected(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isAuthorized')->willReturn(false);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getId')->willReturn('5');

        $this->captureAdapter->expects(self::never())->method('createCapture');

        $this->expectException(\DomainException::class);

        $this->service->capture($contract);
    }

    private function authorizedContract(string $providerOrderId): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isAuthorized')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getId')->willReturn('5');
        $contract->method('getCapturedAmount')->willReturn(null);

        return $contract;
    }

    private function payment(string $id, float $amount, float $amountRemaining): MolliePaymentDto
    {
        return new MolliePaymentDto(
            id: $id,
            status: 'authorized',
            amount: MollieAmountDto::fromComponents('EUR', $amount),
            amountRemaining: $amountRemaining,
        );
    }

    private function captureDto(string $id, string $paymentId, float $amount): MollieCaptureDto
    {
        return new MollieCaptureDto($id, $paymentId, MollieAmountDto::fromComponents('EUR', $amount), 'succeeded');
    }
}
