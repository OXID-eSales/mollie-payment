<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedback;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminValidationFeedback::class)]
final class AdminValidationFeedbackTest extends TestCase
{
    private SessionAdapterInterface&MockObject $session;
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

        $this->feedback = new AdminValidationFeedback($this->session);
    }

    public function testConsume_WithNothingStored_ReturnsEmptyList(): void
    {
        self::assertSame([], $this->feedback->consume('order-1'));
    }

    public function testRejectWithMessage_IsConsumedOnce(): void
    {
        $this->feedback->rejectWithMessage('order-1', 'Not valid');

        self::assertSame(['Not valid'], $this->feedback->consume('order-1'));
        self::assertSame([], $this->feedback->consume('order-1'), 'consume() clears the stored messages');
    }

    public function testRejectWithMessage_TwiceForSameOrder_AccumulatesMessages(): void
    {
        $this->feedback->rejectWithMessage('order-2', 'first message');
        $this->feedback->rejectWithMessage('order-2', 'second message');

        self::assertSame(['first message', 'second message'], $this->feedback->consume('order-2'));
    }

    public function testRejectWithMessage_IsNamespacedPerOrder(): void
    {
        $this->feedback->rejectWithMessage('order-a', 'boom');

        self::assertSame([], $this->feedback->consume('order-b'));
        self::assertSame(['boom'], $this->feedback->consume('order-a'));
    }

    public function testRejectWithMessage_StoresTheMessageAsIs(): void
    {
        $this->feedback->rejectWithMessage('order-3', 'The refund description field is not valid. Allowed symbols are: letters');

        self::assertSame(
            ['The refund description field is not valid. Allowed symbols are: letters'],
            $this->feedback->consume('order-3'),
        );
    }
}
