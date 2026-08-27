<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBounds;
use OxidEsales\Payments\Mollie\Admin\MolliePanelViewDataBuilder;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentSnapshotProvider;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentSnapshotProviderInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieUrlBuilder;
use OxidEsales\Payments\Mollie\Service\TransactionHistoryServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(MolliePanelViewDataBuilder::class)]
final class MolliePanelViewDataBuilderTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;
    private TransactionHistoryServiceInterface&MockObject $transactionHistory;
    private AdminActionBoundsInterface&MockObject $bounds;
    private MollieUrlBuilder $urlBuilder;
    private AdminValidationFeedbackInterface&MockObject $validationFeedback;
    private MolliePaymentSnapshotProviderInterface&MockObject $snapshots;
    private MolliePanelViewDataBuilder $builder;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->transactionHistory = $this->createMock(TransactionHistoryServiceInterface::class);
        $this->bounds = $this->createMock(AdminActionBoundsInterface::class);
        $moduleConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $moduleConfig->method('isTestMode')->willReturn(true);
        $this->urlBuilder = new MollieUrlBuilder($moduleConfig);
        $this->validationFeedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $this->validationFeedback->method('consume')->willReturn([]);

        $this->snapshots = $this->createMock(MolliePaymentSnapshotProviderInterface::class);

        $this->builder = new MolliePanelViewDataBuilder(
            $this->contracts,
            $this->transactionHistory,
            $this->bounds,
            $this->urlBuilder,
            $this->validationFeedback,
            $this->snapshots,
            $this->translatorStub(),
        );
    }

    public function testBuild_WithNoContract_ReturnsEmptyViewWithErrorMessage(): void
    {
        $order = $this->stubOrder('order-1');
        $this->contracts->method('findByOrderId')->with('order-1')->willReturn(null);

        $viewData = $this->builder->build($order);

        self::assertSame('order-1', $viewData['orderId']);
        self::assertNotNull($viewData['errorMessage']);
        self::assertFalse($viewData['isRefundable']);
        self::assertFalse($viewData['isCapturable']);
        self::assertSame([], $viewData['transactions']);
    }

    public function testBuild_IncludesCaptureAndRefundBounds(): void
    {
        $order = $this->stubOrder('order-2');
        $contract = $this->authorizedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->with($contract)->willReturn(45.5);
        $this->bounds->method('refundBound')->with($contract)->willReturn(0.0);
        $this->bounds->method('isAuthorizedHold')->with($contract)->willReturn(true);
        $this->transactionHistory->method('fetch')->willReturn([]);

        $viewData = $this->builder->build($order);

        self::assertSame(45.5, $viewData['captureBound']);
        self::assertSame('45.50', $viewData['captureBoundFormatted']);
        self::assertTrue($viewData['isCapturable']);
        self::assertTrue($viewData['isCancellable']);
        self::assertFalse($viewData['isRefundable'], 'refund bound is 0.0, so refund must not be offered');
    }

    public function testBuild_CommittedContractWithLiveAuthorizedHold_IsCapturableAndCancellable(): void
    {
        // The fix: a manual-capture order is COMMITTED by the shared return chain, but its Mollie
        // payment is still an uncaptured `authorized` hold — capture/cancel must still be offered.
        $order = $this->stubOrder('order-committed');
        $contract = $this->committedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->willReturn(50.0);
        $this->bounds->method('refundBound')->willReturn(0.0);
        $this->bounds->method('isAuthorizedHold')->willReturn(true);
        $this->transactionHistory->method('fetch')->willReturn([]);

        $viewData = $this->builder->build($order);

        self::assertTrue($viewData['isCapturable']);
        self::assertTrue($viewData['isCancellable']);
    }

    public function testBuild_WhenNotAuthorizedHold_NotCapturableOrCancellable(): void
    {
        // No live authorized hold (e.g. already captured/paid): buttons hidden even if a stale
        // capture bound is reported.
        $order = $this->stubOrder('order-nohold');
        $contract = $this->committedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->willReturn(50.0);
        $this->bounds->method('refundBound')->willReturn(0.0);
        $this->bounds->method('isAuthorizedHold')->willReturn(false);
        $this->transactionHistory->method('fetch')->willReturn([]);

        $viewData = $this->builder->build($order);

        self::assertFalse($viewData['isCapturable']);
        self::assertFalse($viewData['isCancellable']);
    }

    public function testBuild_IncludesOrderNumberAndPaymentType(): void
    {
        $order = $this->stubOrder('order-fields', '4711', 'oe_payments_mollie');
        $contract = $this->authorizedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->willReturn(10.0);
        $this->bounds->method('refundBound')->willReturn(0.0);
        $this->transactionHistory->method('fetch')->willReturn([]);

        $viewData = $this->builder->build($order);

        self::assertSame('4711', $viewData['orderNumber']);
        self::assertSame('oe_payments_mollie', $viewData['paymentType']);
    }

    public function testBuild_FulfilledContractWithRefundBalance_IsRefundable(): void
    {
        $order = $this->stubOrder('order-3');
        $contract = $this->fulfilledContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->willReturn(0.0);
        $this->bounds->method('refundBound')->willReturn(20.0);
        $this->transactionHistory->method('fetch')->willReturn([]);

        $viewData = $this->builder->build($order);

        self::assertTrue($viewData['isRefundable']);
        self::assertFalse($viewData['isCapturable']);
    }

    // =========================================================================
    // Story 2: View Cache Reset (no-op for Mollie)
    // =========================================================================

    public function testResetViewCache_NoOpDoesNotThrow(): void
    {
        // Mollie reads directly from API on each fetch(), so resetViewCache() is a no-op.
        // This test ensures the method exists and doesn't throw.
        $this->builder->resetViewCache();
        $this->assertTrue(true); // Assert passes if no exception thrown
    }

    private function stubOrder(string $id, string $orderNumber = '', string $paymentType = ''): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getFieldData')->willReturnMap([
            ['oxordernr', $orderNumber],
            ['oxpaymenttype', $paymentType],
        ]);

        return $order;
    }

    private function authorizedContract(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getProviderOrderId')->willReturn('tr_1');
        $contract->method('getStateValue')->willReturn('authorized');
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getState')->willReturn(ContractState::authorized());

        return $contract;
    }

    private function committedContract(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-c');
        $contract->method('getProviderOrderId')->willReturn('tr_c');
        $contract->method('getStateValue')->willReturn('committed');
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getState')->willReturn(ContractState::committed());

        return $contract;
    }

    private function fulfilledContract(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-2');
        $contract->method('getProviderOrderId')->willReturn('tr_2');
        $contract->method('getStateValue')->willReturn('fulfilled');
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getState')->willReturn(ContractState::fulfilled());

        return $contract;
    }

    // -------------------------------------------------------------------------
    // Sprint 136 Story 4: the method the customer actually paid with
    // -------------------------------------------------------------------------

    public function testBuild_ProjectsCreditCardWithBrandAndLast4(): void
    {
        $order = $this->stubOrder('order-pm-card');
        $contract = $this->authorizedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->transactionHistory->method('fetch')->willReturn([]);
        $this->snapshots->method('snapshot')->with($contract)->willReturn(
            $this->payment('creditcard', 'Visa', '4242')
        );

        $viewData = $this->builder->build($order);

        self::assertTrue($viewData['paymentMethod']['isKnown']);
        self::assertSame('Credit card', $viewData['paymentMethod']['label']);
        self::assertSame('Visa •••• 4242', $viewData['paymentMethod']['detail']);
        self::assertSame('creditcard', $viewData['paymentMethod']['raw']);
    }

    public function testBuild_ProjectsKlarnaWithoutCardDetail(): void
    {
        $order = $this->stubOrder('order-pm-klarna');
        $contract = $this->authorizedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->transactionHistory->method('fetch')->willReturn([]);
        $this->snapshots->method('snapshot')->willReturn($this->payment('klarnapaylater'));

        $viewData = $this->builder->build($order);

        self::assertSame('Klarna', $viewData['paymentMethod']['label']);
        self::assertNull($viewData['paymentMethod']['detail']);
    }

    public function testBuild_ProjectsWalletAsTheLabelWithTheCardDemoted(): void
    {
        $order = $this->stubOrder('order-pm-wallet');
        $contract = $this->authorizedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->transactionHistory->method('fetch')->willReturn([]);
        $this->snapshots->method('snapshot')->willReturn(
            $this->payment('creditcard', 'Mastercard', '0007', 'applepay')
        );

        $viewData = $this->builder->build($order);

        self::assertSame('Apple Pay', $viewData['paymentMethod']['label']);
        self::assertSame('Mastercard •••• 0007', $viewData['paymentMethod']['detail']);
    }

    public function testBuild_WhenMollieCannotBeRead_MethodIsUnknown(): void
    {
        $order = $this->stubOrder('order-pm-down');
        $contract = $this->authorizedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->transactionHistory->method('fetch')->willReturn([]);
        $this->snapshots->method('snapshot')->willReturn(null);

        $viewData = $this->builder->build($order);

        self::assertFalse($viewData['paymentMethod']['isKnown']);
        self::assertSame('', $viewData['paymentMethod']['label']);
        self::assertNull($viewData['paymentMethod']['detail']);
    }

    public function testBuild_WithNoContract_StillCarriesAnUnknownMethodShape(): void
    {
        // The template reads paymentMethod unconditionally; the no-contract
        // branch must not hand it an undefined key.
        $this->contracts->method('findByOrderId')->willReturn(null);

        $viewData = $this->builder->build($this->stubOrder('order-none'));

        self::assertFalse($viewData['paymentMethod']['isKnown']);
        self::assertSame('', $viewData['paymentMethod']['label']);
    }

    public function testBuild_UnmappedMethodShowsTheRawMollieCode(): void
    {
        $order = $this->stubOrder('order-pm-new');
        $contract = $this->authorizedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->transactionHistory->method('fetch')->willReturn([]);
        $this->snapshots->method('snapshot')->willReturn($this->payment('some_new_method'));

        $viewData = $this->builder->build($order);

        self::assertSame('some_new_method', $viewData['paymentMethod']['label']);
    }

    public function testBuild_UntranslatedKeyFallsBackToTheRawCode(): void
    {
        $order = $this->stubOrder('order-pm-untranslated');
        $contract = $this->authorizedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->transactionHistory->method('fetch')->willReturn([]);
        $this->snapshots->method('snapshot')->willReturn($this->payment('ideal'));

        $translator = $this->createMock(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnArgument(0);

        $builder = new MolliePanelViewDataBuilder(
            $this->contracts,
            $this->transactionHistory,
            $this->bounds,
            $this->urlBuilder,
            $this->validationFeedback,
            $this->snapshots,
            $translator,
        );

        self::assertSame('ideal', $builder->build($order)['paymentMethod']['label']);
    }

    /**
     * Story 3's hard gate, asserted where it actually matters: one full panel
     * render — capture bound, refund bound, authorized-hold gate and the
     * payment-method row — costs exactly ONE Mollie API call.
     */
    public function testBuild_WholeRenderCostsOneMollieApiCall(): void
    {
        $order = $this->stubOrder('order-callcount');
        $contract = $this->authorizedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->transactionHistory->method('fetch')->willReturn([]);

        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->expects(self::once())
            ->method('getPayment')
            ->willReturn($this->payment('creditcard', 'Visa', '4242'));

        $snapshots = new MolliePaymentSnapshotProvider($adapter, new NullLogger());

        $builder = new MolliePanelViewDataBuilder(
            $this->contracts,
            $this->transactionHistory,
            new AdminActionBounds($snapshots),
            $this->urlBuilder,
            $this->validationFeedback,
            $snapshots,
            $this->translatorStub(),
        );

        $viewData = $builder->build($order);

        self::assertSame('Credit card', $viewData['paymentMethod']['label']);
        self::assertSame(0.0, $viewData['captureBound'], 'paid payment has nothing capturable');
    }

    private function translatorStub(): LanguageTranslatorInterface
    {
        $translator = $this->createMock(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnMap([
            ['MOLLIE_PAYMENT_METHOD_CREDITCARD', 'Credit card'],
            ['MOLLIE_PAYMENT_METHOD_KLARNA', 'Klarna'],
            ['MOLLIE_PAYMENT_METHOD_APPLE_PAY', 'Apple Pay'],
            ['MOLLIE_PAYMENT_METHOD_IDEAL', 'iDEAL'],
        ]);

        return $translator;
    }

    private function payment(
        string $method,
        ?string $brand = null,
        ?string $last4 = null,
        ?string $wallet = null,
    ): MolliePaymentDto {
        return new MolliePaymentDto(
            id: 'tr_1',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            method: $method,
            cardBrand: $brand,
            cardLast4: $last4,
            walletType: $wallet,
        );
    }
}
