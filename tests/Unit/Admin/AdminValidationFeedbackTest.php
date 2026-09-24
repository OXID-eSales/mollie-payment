<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedback;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminValidationFeedback::class)]
final class AdminValidationFeedbackTest extends TestCase
{
    private SessionAdapterInterface&MockObject $session;
    private MessageFormatterInterface&MockObject $messageFormatter;
    private AdminValidationFeedback $feedback;

    /** @var array<string, mixed> */
    private array $sessionStore = [];

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionAdapterInterface::class);
        $this->session->method('setVariable')->willReturnCallback(function (string $name, mixed $value): void {
            $this->sessionStore[$name] = $value;
        });
        $this->session->method('getVariable')->willReturnCallback(
            fn (string $name): mixed => $this->sessionStore[$name] ?? null,
        );

        $this->messageFormatter = $this->createMock(MessageFormatterInterface::class);
        $this->feedback = new AdminValidationFeedback($this->session, $this->messageFormatter);
    }

    public function testConsume_WithNothingStored_ReturnsEmptyList(): void
    {
        self::assertSame([], $this->feedback->consume('order-1'));
    }

    public function testReject_StoresTheFormattedMessage_ConsumedOnce(): void
    {
        $this->messageFormatter->method('format')
            ->with('refund_amount', 'amountExceedsBound', null)
            ->willReturn('Refund amount exceeds the refundable balance.');

        $this->feedback->reject('order-1', 'refund_amount', 'amountExceedsBound');

        self::assertSame(
            ['Refund amount exceeds the refundable balance.'],
            $this->feedback->consume('order-1'),
        );
        self::assertSame([], $this->feedback->consume('order-1'), 'consume() clears the stored messages');
    }

    public function testReject_TwiceForSameOrder_AccumulatesMessages(): void
    {
        $this->messageFormatter->method('format')->willReturnOnConsecutiveCalls('first message', 'second message');

        $this->feedback->reject('order-2', 'refund_amount', 'amountMalformed');
        $this->feedback->reject('order-2', 'refund_reason', 'amountNotPositive');

        self::assertSame(['first message', 'second message'], $this->feedback->consume('order-2'));
    }

    public function testReject_IsNamespacedPerOrder(): void
    {
        $this->messageFormatter->method('format')->willReturn('boom');

        $this->feedback->reject('order-a', 'refund_amount', 'amountMalformed');

        self::assertSame([], $this->feedback->consume('order-b'));
        self::assertSame(['boom'], $this->feedback->consume('order-a'));
    }

    public function testRejectWithMessage_StoresTheMessageAsIs(): void
    {
        $this->messageFormatter->expects(self::never())->method('format');

        $this->feedback->rejectWithMessage('order-3', 'The refund description field is not valid. Allowed symbols are: letters');

        self::assertSame(
            ['The refund description field is not valid. Allowed symbols are: letters'],
            $this->feedback->consume('order-3'),
        );
    }
}
