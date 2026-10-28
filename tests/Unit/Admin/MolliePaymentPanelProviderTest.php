<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionDispatcherInterface;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionRefusals;
use OxidEsales\Payments\Mollie\Admin\ReleaseRule;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Admin\AdminAmountValidator;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\Admin\MolliePanelOrderLoader;
use OxidEsales\Payments\Mollie\Admin\MolliePanelViewDataBuilder;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentPanelProvider;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Controller\Admin\MollieExtraActionDispatcherInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\UserDataValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MolliePaymentPanelProvider::class)]
final class MolliePaymentPanelProviderTest extends TestCase
{
    private AdminActionDispatcherInterface&MockObject $actionDispatcher;
    private MolliePanelViewDataBuilder&MockObject $viewDataBuilder;
    private TestableMolliePanelOrderLoader $orderLoader;
    private ContractRepositoryInterface&MockObject $contracts;
    private AdminActionBoundsInterface&MockObject $bounds;
    private AdminValidationFeedbackInterface&MockObject $validationFeedback;
    private UserDataValidatorInterface&MockObject $userDataValidator;
    private MessageFormatterInterface&MockObject $textMessageFormatter;
    private MollieExtraActionDispatcherInterface&MockObject $extraActions;
    private LanguageTranslatorInterface&MockObject $translator;
    private MolliePaymentPanelProvider $provider;

    protected function setUp(): void
    {
        $this->actionDispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $this->viewDataBuilder = $this->createMock(MolliePanelViewDataBuilder::class);
        $this->orderLoader = new TestableMolliePanelOrderLoader();
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->bounds = $this->createMock(AdminActionBoundsInterface::class);
        $this->validationFeedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $this->userDataValidator = $this->createMock(UserDataValidatorInterface::class);
        $this->textMessageFormatter = $this->createMock(MessageFormatterInterface::class);
        $this->translator = $this->createMock(LanguageTranslatorInterface::class);
        $this->translator->method('translateString')->willReturnArgument(0);

        $this->extraActions = $this->createMock(MollieExtraActionDispatcherInterface::class);

        $this->provider = new MolliePaymentPanelProvider(
            $this->actionDispatcher,
            $this->viewDataBuilder,
            $this->orderLoader,
            $this->contracts,
            $this->bounds,
            new AdminAmountValidator(),
            $this->userDataValidator,
            new AdminActionRefusals($this->validationFeedback, $this->textMessageFormatter, $this->translator),
            new ReleaseRule($this->bounds),
            $this->extraActions,
        );
    }

    public function testHandleAction_Release_DispatchesTheReleaseActionAndResetsTheViewCache(): void
    {
        $order = $this->stubOrder('order-rel');
        $this->orderLoader->order = $order;
        $contract = $this->releasableContract();
        $this->userDataValidator->method('validateFieldMap')->willReturn([]); // reason accepted

        $this->extraActions->expects(self::once())->method('release')->with($order, 'done capturing')->willReturn(true);
        $this->viewDataBuilder->expects(self::once())->method('resetViewCache');

        $this->provider->handleAction('release', ['release_reason' => 'done capturing'], new PaymentPanelContext(
            'order-rel',
            MollieDefinitions::PAYMENT_ID,
            $contract,
        ));
    }

    public function testHandleAction_Release_WhenNothingMayBeReleased_RefusesWithoutCallingMollie(): void
    {
        $this->orderLoader->order = $this->stubOrder('order-rel-2');
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getCapturedAmount')->willReturn(null); // nothing captured: release would cancel the hold
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('isAuthorizedHold')->willReturn(true);
        $this->bounds->method('captureBound')->willReturn(100.0);
        $this->extraActions->expects(self::never())->method('release');

        try {
            $this->provider->handleAction('release', [], new PaymentPanelContext('order-rel-2', MollieDefinitions::PAYMENT_ID, $contract));
            self::fail('a release that is not possible must be refused');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('not_releasable', $refusal->refusalCode());
            self::assertSame('MOLLIE_ADMIN_RELEASE_NOT_POSSIBLE', $refusal->getMessage());
            self::assertFalse($refusal->feedbackRecorded(), 'nothing was recorded for the classic tab, so it shows the sentence');
        }
    }

    public function testHandleAction_Release_WhenMollieFails_RefusesWithTheRecordedSentence(): void
    {
        $this->orderLoader->order = $this->stubOrder('order-rel-3');
        $contract = $this->releasableContract();
        $this->userDataValidator->method('validateFieldMap')->willReturn([]);
        $this->extraActions->method('release')->willReturn(false);

        try {
            $this->provider->handleAction('release', [], new PaymentPanelContext('order-rel-3', MollieDefinitions::PAYMENT_ID, $contract));
            self::fail('a failed release must be refused');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('release_failed', $refusal->refusalCode());
            self::assertSame('MOLLIE_ADMIN_RELEASE_FAILED', $refusal->getMessage());
            self::assertTrue($refusal->feedbackRecorded(), 'the release handler recorded it for the classic tab already');
        }
    }

    public function testHandleAction_Release_WithAnInvalidReason_RefusesWithoutCallingMollie(): void
    {
        $this->orderLoader->order = $this->stubOrder('order-rel-4');
        $contract = $this->releasableContract();
        $this->userDataValidator->method('validateFieldMap')
            ->willReturn([new FieldValidationFailure('captureReason', 'admin', 'blocked_character', ';', null)]);
        $this->textMessageFormatter->method('format')->willReturn('Not valid');
        $this->validationFeedback->expects(self::once())->method('rejectWithMessage')->with('order-rel-4', 'Not valid');
        $this->extraActions->expects(self::never())->method('release');

        try {
            $this->provider->handleAction('release', ['release_reason' => 'a;b'], new PaymentPanelContext('order-rel-4', MollieDefinitions::PAYMENT_ID, $contract));
            self::fail('an invalid reason must be refused');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('blocked_character', $refusal->refusalCode());
            self::assertSame('Not valid', $refusal->getMessage());
            self::assertTrue($refusal->feedbackRecorded());
        }
    }

    public function testHandleAction_Refund_ExceedingBound_RefusesWithTheSentenceItRecordedForTheClassicTab(): void
    {
        $this->orderLoader->order = $this->stubOrder('order-amt');
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('refundBound')->willReturn(10.0);
        $this->validationFeedback->method('reject')->willReturn('The amount is more than the 10.00 that can be refunded');

        try {
            $this->provider->handleAction('refund', ['refund_amount' => '99.00'], new PaymentPanelContext('order-amt', MollieDefinitions::PAYMENT_ID, $contract));
            self::fail('the amount must be refused');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('amount_exceeds_bound', $refusal->refusalCode());
            self::assertSame('The amount is more than the 10.00 that can be refunded', $refusal->getMessage());
            self::assertTrue($refusal->feedbackRecorded());
        }
    }

    public function testActionOptions_AreTheReasonsAndLimitsOfThePanelsForm(): void
    {
        $options = $this->provider->actionOptions($this->createStub(PaymentContractInterface::class));

        self::assertSame(['requested_by_customer', 'duplicate', 'fraudulent'], $options->refundReasons);
        self::assertSame(['requested_by_customer', 'duplicate', 'fraudulent', 'abandoned'], $options->voidReasons);
        self::assertTrue($options->refundDescription);
        self::assertSame(140, $options->reasonMaxLength);
    }

    public function testBoundsFor_AreTheBoundsTheClassicPanelUses(): void
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $expected = new \OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds(60.0, 12.5);
        $this->bounds->expects(self::once())->method('boundsFor')->with($contract)->willReturn($expected);

        self::assertSame($expected, $this->provider->boundsFor($contract));
    }

    public function testTheProviderImplementsPaymentBasesOptionalProviderInterfaces(): void
    {
        self::assertInstanceOf(\OxidEsales\PaymentBase\Admin\Contract\AdminActionOptionsProviderInterface::class, $this->provider);
        self::assertInstanceOf(\OxidEsales\PaymentBase\Admin\Contract\AdminActionBoundsProviderInterface::class, $this->provider);
    }

    // ── MOL-15: admin free text runs through the same character-level rules as the address ──────

    public function testHandleAction_Refund_WithInvalidDescription_RejectsWithFormattedMessageWithoutDispatching(): void
    {
        $order = $this->stubOrder('order-7');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('refundBound')->willReturn(50.0);
        $this->userDataValidator->method('validateFieldMap')
            ->with(['refundDescription' => 'chargeback <x>'], FieldValidationFailure::KIND_ADMIN)
            ->willReturn([new FieldValidationFailure('refundDescription', 'admin', 'blocked_character', '<', null)]);
        $this->textMessageFormatter->method('format')
            ->with('refundDescription', 'blocked_character', '<')
            ->willReturn('The refund description field is not valid. Allowed symbols are: letters');

        $this->actionDispatcher->expects(self::never())->method('refund');
        $this->validationFeedback->expects(self::once())->method('rejectWithMessage')
            ->with('order-7', 'The refund description field is not valid. Allowed symbols are: letters');

        $this->expectException(AdminActionRefusedException::class);

        $this->provider->handleAction('refund', [
            'refund_amount' => '25.00',
            'refund_description' => 'chargeback <x>',
        ], new PaymentPanelContext('order-7', MollieDefinitions::PAYMENT_ID, $contract));
    }

    public function testHandleAction_Capture_WithInvalidReason_RejectsWithoutDispatching(): void
    {
        $order = $this->stubOrder('order-8');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->willReturn(50.0);
        $this->userDataValidator->method('validateFieldMap')
            ->with(['captureReason' => 'ship; now'], FieldValidationFailure::KIND_ADMIN)
            ->willReturn([new FieldValidationFailure('captureReason', 'admin', 'blocked_character', ';', null)]);
        $this->textMessageFormatter->method('format')->willReturn('msg');

        $this->actionDispatcher->expects(self::never())->method('capture');
        $this->validationFeedback->expects(self::once())->method('rejectWithMessage')->with('order-8', 'msg');

        $this->expectException(AdminActionRefusedException::class);

        $this->provider->handleAction('capture', ['capture_reason' => 'ship; now'], new PaymentPanelContext(
            'order-8',
            MollieDefinitions::PAYMENT_ID,
            $contract,
        ));
    }

    public function testHandleAction_Refund_WithValidDescription_DispatchesIt(): void
    {
        $order = $this->stubOrder('order-9');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('refundBound')->willReturn(50.0);

        $this->validationFeedback->expects(self::never())->method('rejectWithMessage');
        $this->actionDispatcher->expects(self::once())->method('refund')
            ->with($order, 25.0, null, ['description' => 'Damaged on arrival, see ticket #42']);

        $this->provider->handleAction('refund', [
            'refund_amount' => '25.00',
            'refund_description' => 'Damaged on arrival, see ticket #42',
        ], new PaymentPanelContext('order-9', MollieDefinitions::PAYMENT_ID, $contract));
    }

    public function testGetProviderName_ReturnsMollie(): void
    {
        self::assertSame('mollie', $this->provider->getProviderName());
    }

    public function testSupports_MolliePaymentType_ReturnsTrue(): void
    {
        $context = new PaymentPanelContext('order-1', MollieDefinitions::PAYMENT_ID, null);

        self::assertTrue($this->provider->supports($context));
    }

    public function testSupports_NonMollieOrder_ReturnsFalse(): void
    {
        $context = new PaymentPanelContext('order-1', 'oxidpaypal', null);

        self::assertFalse($this->provider->supports($context));
    }

    public function testSupports_ContractProviderMatchesEvenWithDifferentPaymentType(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProvider')->willReturn('mollie');
        $context = new PaymentPanelContext('order-1', 'some_other_id', $contract);

        self::assertTrue($this->provider->supports($context));
    }

    public function testBuild_ReturnsPanelForMollieOrder(): void
    {
        $order = $this->stubOrder('order-1');
        $this->orderLoader->order = $order;
        $this->viewDataBuilder->method('build')->with($order)->willReturn(['orderId' => 'order-1']);

        $context = new PaymentPanelContext('order-1', MollieDefinitions::PAYMENT_ID, null);
        $renderable = $this->provider->build($context);

        self::assertSame('mollie', $renderable->providerKey);
        self::assertSame(['orderId' => 'order-1'], $renderable->viewData);
        self::assertStringContainsString('mollie_panel', $renderable->templatePath);
    }

    public function testProvide_ForNonMollieOrder_ReturnsNull(): void
    {
        // supports() is what gates non-Mollie orders out at the registry level; build() itself
        // degrades to an empty view when no live order resolves (never null — contract).
        $this->orderLoader->order = null;

        $context = new PaymentPanelContext('missing-order', MollieDefinitions::PAYMENT_ID, null);
        $renderable = $this->provider->build($context);

        self::assertSame([], $renderable->viewData);
    }

    public function testHandleAction_Refund_WithValidAmount_DispatchesRefund(): void
    {
        $order = $this->stubOrder('order-2');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->with('order-2')->willReturn($contract);
        $this->bounds->method('refundBound')->with($contract)->willReturn(50.0);

        $this->actionDispatcher->expects(self::once())->method('refund')
            ->with($order, 25.0, 'customer request');
        $this->validationFeedback->expects(self::never())->method('reject');

        $this->provider->handleAction('refund', [
            'refund_amount' => '25.00',
            'refund_reason' => 'customer request',
        ], new PaymentPanelContext('order-2', MollieDefinitions::PAYMENT_ID, $contract));
    }

    public function testHandleAction_Refund_ExceedingBound_RejectsWithoutDispatching(): void
    {
        $order = $this->stubOrder('order-3');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('refundBound')->willReturn(10.0);

        $this->actionDispatcher->expects(self::never())->method('refund');
        $this->validationFeedback->expects(self::once())->method('reject')
            ->with('order-3', 'refund_amount', 'amountExceedsBound');

        $this->expectException(AdminActionRefusedException::class);

        $this->provider->handleAction('refund', ['refund_amount' => '99.00'], new PaymentPanelContext(
            'order-3',
            MollieDefinitions::PAYMENT_ID,
            $contract,
        ));
    }

    public function testHandleAction_Capture_WithFullAmount_DispatchesNullAmount(): void
    {
        $order = $this->stubOrder('order-4');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->willReturn(100.0);

        $this->actionDispatcher->expects(self::once())->method('capture')
            ->with($order, null, null);

        $this->provider->handleAction('capture', [], new PaymentPanelContext(
            'order-4',
            MollieDefinitions::PAYMENT_ID,
            $contract,
        ));
    }

    public function testHandleAction_Cancel_DispatchesCancelWithReason(): void
    {
        $order = $this->stubOrder('order-5');
        $this->orderLoader->order = $order;

        $this->actionDispatcher->expects(self::once())->method('cancel')
            ->with($order, 'fraud suspicion');

        $this->provider->handleAction('cancel', ['cancel_reason' => 'fraud suspicion'], new PaymentPanelContext(
            'order-5',
            MollieDefinitions::PAYMENT_ID,
            null,
        ));
    }

    public function testHandleAction_WhenOrderCannotBeLoaded_DoesNothing(): void
    {
        $this->orderLoader->order = null;

        $this->actionDispatcher->expects(self::never())->method('refund');
        $this->actionDispatcher->expects(self::never())->method('capture');
        $this->actionDispatcher->expects(self::never())->method('cancel');

        $this->provider->handleAction('refund', [], new PaymentPanelContext(
            'missing',
            MollieDefinitions::PAYMENT_ID,
            null,
        ));
    }

    // =========================================================================
    // Story 2: View Cache Reset After Admin Actions
    // =========================================================================

    public function testHandleAction_Refund_ResetsViewCacheAfterAction(): void
    {
        $order = $this->stubOrder('order-cache-1');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('refundBound')->willReturn(50.0);

        $this->actionDispatcher->expects(self::once())->method('refund');
        $this->viewDataBuilder->expects(self::once())->method('resetViewCache');

        $this->provider->handleAction('refund', ['refund_amount' => '25.00'], new PaymentPanelContext(
            'order-cache-1',
            MollieDefinitions::PAYMENT_ID,
            $contract,
        ));
    }

    public function testHandleAction_Capture_ResetsViewCacheAfterAction(): void
    {
        $order = $this->stubOrder('order-cache-2');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->willReturn(100.0);

        $this->actionDispatcher->expects(self::once())->method('capture');
        $this->viewDataBuilder->expects(self::once())->method('resetViewCache');


        $this->provider->handleAction('capture', [], new PaymentPanelContext(
            'order-cache-2',
            MollieDefinitions::PAYMENT_ID,
            $contract,
        ));
    }

    public function testHandleAction_Cancel_ResetsViewCacheAfterAction(): void
    {
        $order = $this->stubOrder('order-cache-3');
        $this->orderLoader->order = $order;

        $this->actionDispatcher->expects(self::once())->method('cancel');
        $this->viewDataBuilder->expects(self::once())->method('resetViewCache');

        $this->provider->handleAction('cancel', ['cancel_reason' => 'abandoned'], new PaymentPanelContext(
            'order-cache-3',
            MollieDefinitions::PAYMENT_ID,
            null,
        ));
    }

    public function testHandleAction_Refund_ValidationFails_DoesNotResetCache(): void
    {
        $order = $this->stubOrder('order-cache-4');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('refundBound')->willReturn(10.0);


        $this->actionDispatcher->expects(self::never())->method('refund');
        // Cache should NOT be reset when validation fails (no action was taken)
        $this->viewDataBuilder->expects(self::never())->method('resetViewCache');
        $this->validationFeedback->expects(self::once())->method('reject');

        $this->expectException(AdminActionRefusedException::class);

        $this->provider->handleAction('refund', ['refund_amount' => '99.00'], new PaymentPanelContext(
            'order-cache-4',
            MollieDefinitions::PAYMENT_ID,
            $contract,
        ));
    }

    private function releasableContract(): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getCapturedAmount')->willReturn(40.0);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('isAuthorizedHold')->willReturn(true);
        $this->bounds->method('captureBound')->willReturn(60.0);

        return $contract;
    }

    private function stubOrder(string $id): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);

        return $order;
    }
}

/**
 * Test seam standing in for {@see MolliePanelOrderLoader} — avoids exercising `oxNew()`.
 */
final class TestableMolliePanelOrderLoader extends MolliePanelOrderLoader
{
    public ?Order $order = null;

    public function loadById(string $orderId): ?Order
    {
        return $this->order;
    }
}
