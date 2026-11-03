<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\EventSystem\Translator;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CancelAuthorizationRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CaptureRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCancelAuthorizationRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCaptureRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieRefundRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Translator\MollieEventTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieEventTranslator::class)]
final class MollieEventTranslatorTest extends TestCase
{
    private MollieEventTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new MollieEventTranslator();
    }

    public function testSupports_OnlyMollieProvider(): void
    {
        self::assertTrue($this->translator->supports('mollie'));
        self::assertFalse($this->translator->supports('paypal'));
        self::assertFalse($this->translator->supports('stripe'));
    }

    public function testTranslate_RefundRequested_ToMollieRefundRequestEvent(): void
    {
        $contract = $this->contractWithId('contract-1');
        $context = $this->contextWithContract($contract);

        $translated = $this->translator->translate(new RefundRequestedEvent($context, 25.0, 'requested'));

        self::assertInstanceOf(MollieRefundRequestEvent::class, $translated);
        self::assertSame($contract, $translated->contract);
        self::assertSame(25.0, $translated->amount);
        self::assertSame('requested', $translated->reason);
        self::assertSame('contract-1:refund:25.00:after:0.00', $translated->idempotencyKey);
        self::assertNull($translated->description);
    }

    public function testTranslate_RefundRequested_WithDescription_PassesThroughToEvent(): void
    {
        $contract = $this->contractWithId('contract-desc-1');
        $context = $this->contextWithContract($contract);
        $context->set('refundDescription', 'Admin note: customer requested');

        $translated = $this->translator->translate(new RefundRequestedEvent($context, 25.0, 'requested'));


        self::assertInstanceOf(MollieRefundRequestEvent::class, $translated);
        self::assertSame('Admin note: customer requested', $translated->description);
    }

    public function testTranslate_CaptureRequested_ToMollieCaptureRequestEvent(): void
    {
        $contract = $this->contractWithId('contract-2');
        $context = $this->contextWithContract($contract);

        $translated = $this->translator->translate(new CaptureRequestedEvent($context, 40.0));

        self::assertInstanceOf(MollieCaptureRequestEvent::class, $translated);
        self::assertSame(40.0, $translated->amount);
        self::assertSame('contract-2:capture:40.00:after:0.00', $translated->idempotencyKey);
    }

    public function testTranslate_CaptureRequested_FullAmount_KeySaysFull(): void
    {
        $contract = $this->contractWithId('contract-3');
        $context = $this->contextWithContract($contract);

        $translated = $this->translator->translate(new CaptureRequestedEvent($context));

        self::assertInstanceOf(MollieCaptureRequestEvent::class, $translated);
        self::assertSame('contract-3:capture:full:after:0.00', $translated->idempotencyKey);
    }

    public function testTranslate_CancelAuthorizationRequested_ToMollieCancelAuthorizationRequestEvent(): void
    {
        $contract = $this->contractWithId('contract-4');
        $context = $this->contextWithContract($contract);

        $translated = $this->translator->translate(new CancelAuthorizationRequestedEvent($context, null, 'fraud'));

        self::assertInstanceOf(MollieCancelAuthorizationRequestEvent::class, $translated);
        self::assertSame('fraud', $translated->reason);
        self::assertSame('contract-4:cancel_authorization', $translated->idempotencyKey);
    }

    public function testTranslate_WithoutContract_ReturnsNull(): void
    {
        $context = new EventContext();

        $translated = $this->translator->translate(new RefundRequestedEvent($context, 10.0));

        self::assertNull($translated);
    }

    /**
     * VR-21 F1 — the key names the contract state the action starts from: a double click
     * or retry repeats the key (Mollie runs it once); a second refund of the same amount
     * after the first one was booked starts from another state and gets its own key.
     */
    public function testASecondRefundOfTheSameAmountGetsItsOwnKey(): void
    {
        $first = $this->translator->translate(
            new RefundRequestedEvent($this->contextWithContract($this->contractWithId('contract-5')), 10.0, 'r')
        );
        $second = $this->translator->translate(
            new RefundRequestedEvent($this->contextWithContract($this->contractWithId('contract-5', refunded: 10.0)), 10.0, 'r')
        );

        self::assertSame('contract-5:refund:10.00:after:0.00', $first->idempotencyKey);
        self::assertSame('contract-5:refund:10.00:after:10.00', $second->idempotencyKey);
    }

    public function testASecondPartialCaptureOfTheSameAmountGetsItsOwnKey(): void
    {
        $second = $this->translator->translate(
            new CaptureRequestedEvent($this->contextWithContract($this->contractWithId('contract-6', captured: 40.0)), 40.0, 'c')
        );

        self::assertSame('contract-6:capture:40.00:after:40.00', $second->idempotencyKey);
    }

    private function contractWithId(string $id, ?float $refunded = null, ?float $captured = null): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($id);
        $contract->method('getRefundedAmount')->willReturn($refunded);
        $contract->method('getCapturedAmount')->willReturn($captured);

        return $contract;
    }

    private function contextWithContract(PaymentContractInterface $contract): EventContext
    {
        $context = new EventContext();
        $context->setContract($contract);

        return $context;
    }
}
