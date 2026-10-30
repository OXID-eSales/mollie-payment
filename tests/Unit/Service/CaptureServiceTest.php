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
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\CaptureRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\CaptureNotSupportedException;
use OxidEsales\Payments\Mollie\Adapter\MollieCaptureAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Service\CaptureCompletionPolicy;
use OxidEsales\Payments\Mollie\Service\CaptureService;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CaptureService::class)]
final class CaptureServiceTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $paymentsAdapter;
    private MollieCaptureAdapterInterface&MockObject $captureAdapter;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractFulfillmentServiceInterface&MockObject $fulfillmentService;
    private CaptureService $service;

    protected function setUp(): void
    {
        $this->paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->captureAdapter = $this->createMock(MollieCaptureAdapterInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->fulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);

        // Default: single-capture account — the policy always answers FullyCaptured, so the
        // existing (pre-MOL-32) behaviour is preserved.
        $this->service = $this->serviceWithCompletionPolicy($this->policy(multiCapture: false));
    }

    private function serviceWithCompletionPolicy(CaptureCompletionPolicy $policy): CaptureService
    {
        return new CaptureService(
            $this->paymentsAdapter,
            $this->captureAdapter,
            $this->contractRepository,
            $this->fulfillmentService,
            $policy,
        );
    }

    private function serviceWithMultiCapture(): CaptureService
    {
        return $this->serviceWithCompletionPolicy($this->policy(multiCapture: true));
    }

    private function policy(bool $multiCapture): CaptureCompletionPolicy
    {
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);
        $config->method('isMultiCaptureEnabled')->willReturn($multiCapture);

        return new CaptureCompletionPolicy($config);
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

    public function testCapture_CommittedContract_CapturesAndFulfills(): void
    {
        // Manual-capture order finalized by the shared return chain: contract COMMITTED while the
        // Mollie payment is still an uncaptured `authorized` hold. Capture must succeed and
        // complete fulfillment (COMMITTED -> FULFILLED) rather than call captureAuthorization().
        $contract = $this->committedContract('tr_c');
        $this->paymentsAdapter->method('getPayment')->with('tr_c')->willReturn(
            $this->payment('tr_c', 100.0, 100.0),
        );
        $this->captureAdapter->expects(self::once())
            ->method('createCapture')
            ->willReturn($this->captureDto('cp_c', 'tr_c', 100.0));

        $contract->expects(self::once())->method('setCapturedAmount')->with(100.0);
        $contract->expects(self::never())->method('captureAuthorization');
        $this->fulfillmentService->expects(self::once())->method('fulfill')->with($contract);

        $dto = $this->service->capture($contract);

        self::assertSame('cp_c', $dto->id);
    }

    /**
     * CFG-1 S8: an admin capture moved the money and the contract but left no row in the transaction table, so the
     * Payment tab of the admin app did not list it (only a webhook that fulfils the contract wrote one, without a reference).
     */
    public function testCapture_IsListedInTheTransactionTableWithMolliesCaptureId(): void
    {
        $contract = $this->committedContract('tr_c');
        $this->paymentsAdapter->method('getPayment')->willReturn($this->payment('tr_c', 100.0, 100.0));
        $this->captureAdapter->method('createCapture')->willReturn($this->captureDto('cpt_1', 'tr_c', 40.0));
        $audit = $this->auditRecorderExpecting('capture', 40.0, 'cpt_1');
        $service = new CaptureService(
            $this->paymentsAdapter,
            $this->captureAdapter,
            $this->contractRepository,
            $this->fulfillmentService,
            $this->policy(multiCapture: true),
            $audit,
        );

        $service->capture($contract, 40.0);
    }

    // ---- MOL-32 S3: a partial capture under multi-capture must NOT fulfil / stamp OXPAID ----

    public function testCapture_MultiCapture_PartialOnCommitted_RecordsButDoesNotFulfil(): void
    {
        $contract = $this->committedContract('tr_mc');
        $this->paymentsAdapter->method('getPayment')->with('tr_mc')->willReturn(
            $this->payment('tr_mc', 100.0, 100.0),
        );
        $this->captureAdapter->method('createCapture')->willReturn($this->captureDto('cp_mc', 'tr_mc', 40.0));

        $contract->expects(self::once())->method('setCapturedAmount')->with(40.0);
        $contract->expects(self::never())->method('captureAuthorization');
        // 40 of 100 captured, 60 still authorized → the order stays open, nothing is fulfilled.
        $this->fulfillmentService->expects(self::never())->method('fulfill');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);

        $this->serviceWithMultiCapture()->capture($contract, 40.0);
    }

    public function testCapture_MultiCapture_PartialOnAuthorized_LeavesItAuthorized(): void
    {
        $contract = $this->authorizedContract('tr_ma');
        $this->paymentsAdapter->method('getPayment')->with('tr_ma')->willReturn(
            $this->payment('tr_ma', 100.0, 100.0),
        );
        $this->captureAdapter->method('createCapture')->willReturn($this->captureDto('cp_ma', 'tr_ma', 30.0));

        // A remainder is still authorized → do not advance the ladder, keep it capturable.
        $contract->expects(self::never())->method('captureAuthorization');
        $this->fulfillmentService->expects(self::never())->method('fulfill');

        $this->serviceWithMultiCapture()->capture($contract, 30.0);
    }

    public function testCapture_MultiCapture_FinalCaptureCompletesTheOrder(): void
    {
        // 60 already captured; this 40 completes the 100 authorization → fulfil.
        $contract = $this->committedContract('tr_mf', captured: 60.0);
        $this->paymentsAdapter->method('getPayment')->with('tr_mf')->willReturn(
            $this->payment('tr_mf', 100.0, 40.0),
        );
        $this->captureAdapter->method('createCapture')->willReturn($this->captureDto('cp_mf', 'tr_mf', 40.0));

        $contract->expects(self::once())->method('setCapturedAmount')->with(100.0);
        $this->fulfillmentService->expects(self::once())->method('fulfill')->with($contract);

        $this->serviceWithMultiCapture()->capture($contract, 40.0);
    }

    // ---- MOL-32 S4: a second capture is allowed while an authorized remainder exists ----

    public function testCapture_MultiCapture_SecondPartialCaptureIsAccepted_AndStillDoesNotFulfil(): void
    {
        // 60 already captured; Mollie reports 60 captured (40 still capturable). Capturing a further
        // 30 is accepted (the contract never became FULFILLED), leaves 10 — still open.
        $contract = $this->committedContract('tr_s2', captured: 60.0);
        $this->paymentsAdapter->method('getPayment')->with('tr_s2')->willReturn(
            new MolliePaymentDto('tr_s2', 'authorized', MollieAmountDto::fromComponents('EUR', 100.0), amountCaptured: 60.0),
        );
        $this->captureAdapter->expects(self::once())
            ->method('createCapture')
            ->willReturn($this->captureDto('cp_s2', 'tr_s2', 30.0));

        $contract->expects(self::once())->method('setCapturedAmount')->with(90.0);
        $this->fulfillmentService->expects(self::never())->method('fulfill');

        $this->serviceWithMultiCapture()->capture($contract, 30.0);
    }

    public function testCapture_MultiCapture_CaptureBeyondTheAuthorizedRemainderIsRejected(): void
    {
        // 60 captured at Mollie → only 40 still capturable; asking for 50 is refused before any call.
        $contract = $this->committedContract('tr_s3', captured: 60.0);
        $this->paymentsAdapter->method('getPayment')->with('tr_s3')->willReturn(
            new MolliePaymentDto('tr_s3', 'authorized', MollieAmountDto::fromComponents('EUR', 100.0), amountCaptured: 60.0),
        );
        $this->captureAdapter->expects(self::never())->method('createCapture');

        $this->expectException(\InvalidArgumentException::class);

        $this->serviceWithMultiCapture()->capture($contract, 50.0);
    }

    public function testCapture_WhenNotInCapturableState_Rejected(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isAuthorized')->willReturn(false);
        $state->method('isCommitted')->willReturn(false);

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
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn(null);

        return $contract;
    }

    private function committedContract(string $providerOrderId, ?float $captured = null): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isAuthorized')->willReturn(false);
        $state->method('isCommitted')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getId')->willReturn('7');
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn($captured);

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

    private function auditRecorderExpecting(string $type, float $amount, string $reference): TransactionAuditRecorder
    {
        $shop = $this->createMock(\OxidEsales\PaymentBase\Adapter\ShopAdapterInterface::class);
        $shop->method('getShopId')->willReturn('1');
        $rows = $this->createMock(\OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface::class);
        $rows->expects(self::once())->method('save')->with(self::callback(
            static fn (\OxidEsales\PaymentBase\Contract\Transaction $row): bool => $row->getType() === $type
                && $row->getAmount() === $amount
                && $row->getTransactionId() === $reference
        ));

        return new TransactionAuditRecorder($rows, $shop);
    }
}
