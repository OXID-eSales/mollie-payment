<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter\Dto;

use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(MolliePaymentDto::class)]
final class MolliePaymentDtoTest extends TestCase
{
    public function testFromArray_MapsStatusAmountAndCheckoutUrl(): void
    {
        $dto = MolliePaymentDto::fromArray([
            'id' => 'tr_abc',
            'status' => 'open',
            'amount' => ['currency' => 'EUR', 'value' => '24.00'],
            'checkoutUrl' => 'https://www.mollie.com/checkout/tr_abc',
            'method' => 'ideal',
            'metadata' => ['order_number' => '4711'],
            'amountRefunded' => '0.00',
        ]);

        self::assertSame('tr_abc', $dto->id);
        self::assertSame('open', $dto->status);
        self::assertSame('EUR', $dto->amount->currency);
        self::assertSame(24.0, $dto->amount->value);
        self::assertSame('https://www.mollie.com/checkout/tr_abc', $dto->checkoutUrl);
        self::assertSame('ideal', $dto->method);
        self::assertSame(['order_number' => '4711'], $dto->metadata);
    }

    public function testFromArray_DefaultsWhenAbsent(): void
    {
        $dto = MolliePaymentDto::fromArray(['id' => 'tr_x', 'status' => 'paid']);
        self::assertNull($dto->checkoutUrl);
        self::assertNull($dto->method);
        self::assertSame([], $dto->metadata);
        self::assertSame(0.0, $dto->amountRefunded);
        self::assertSame(0.0, $dto->amountChargedBack);
        self::assertNull($dto->createdAt);
    }

    public function testFromArray_MapsCreatedAt(): void
    {
        $dto = MolliePaymentDto::fromArray([
            'id' => 'tr_ts',
            'status' => 'paid',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'createdAt' => '2026-07-08T10:15:30+00:00',
        ]);

        self::assertSame('2026-07-08T10:15:30+00:00', $dto->createdAt);
    }

    public function testFromArray_MapsAmountChargedBack(): void
    {
        $dto = MolliePaymentDto::fromArray([
            'id' => 'tr_cb',
            'status' => 'paid',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'amountChargedBack' => '10.00',
        ]);

        self::assertSame(10.0, $dto->amountChargedBack);
    }

    public function testIsReadonly_HasNoSetters(): void
    {
        self::assertTrue((new ReflectionClass(MolliePaymentDto::class))->isReadOnly());
    }

    public function testRefundableAmount_SubtractsRefundedAndChargedBack(): void
    {
        $dto = MolliePaymentDto::fromArray([
            'id' => 'tr_1',
            'status' => 'paid',
            'amount' => ['currency' => 'EUR', 'value' => '100.00'],
            'amountRefunded' => '20.00',
            'amountChargedBack' => '5.00',
        ]);

        self::assertSame(75.0, $dto->refundableAmount());
    }

    public function testRefundableAmount_NeverNegative(): void
    {
        $dto = MolliePaymentDto::fromArray([
            'id' => 'tr_1',
            'status' => 'paid',
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'amountRefunded' => '10.00',
            'amountChargedBack' => '5.00',
        ]);

        self::assertSame(0.0, $dto->refundableAmount());
    }

    public function testCapturableAmount_BeforeAnyCapture_IsTheFullAuthorizedAmount(): void
    {
        $dto = MolliePaymentDto::fromArray([
            'id' => 'tr_1',
            'status' => 'authorized',
            'amount' => ['currency' => 'EUR', 'value' => '100.00'],
        ]);

        self::assertSame(100.0, $dto->capturableAmount());
    }

    /**
     * MOL-32 — under multi-capture the payment stays `authorized` after a partial capture and the
     * remaining capturable amount is `amount − amountCaptured`. Mollie's `amountRemaining` is
     * refund-oriented ("only available when refunds are available") and must NOT drive the capture
     * bound: before MOL-32 the formula fell back to the full `amount`, so a 40.00 capture on a
     * 100.00 authorization reported 100.00 capturable again.
     */
    public function testCapturableAmount_AfterPartialCapture_IsAmountMinusCaptured(): void
    {
        $dto = MolliePaymentDto::fromArray([
            'id' => 'tr_1',
            'status' => 'authorized',
            'amount' => ['currency' => 'EUR', 'value' => '100.00'],
            'amountCaptured' => '40.00',
        ]);

        self::assertSame(60.0, $dto->capturableAmount());
    }

    public function testCapturableAmount_RefundOrientedAmountRemainingDoesNotDriveTheCaptureBound(): void
    {
        // An authorized payment that has settled a partial capture: amountRemaining here is the
        // refundable remainder of the captured part, not a capturable figure.
        $dto = MolliePaymentDto::fromArray([
            'id' => 'tr_1',
            'status' => 'authorized',
            'amount' => ['currency' => 'EUR', 'value' => '100.00'],
            'amountCaptured' => '70.00',
            'amountRemaining' => '70.00',
        ]);

        self::assertSame(30.0, $dto->capturableAmount());
    }

    public function testCapturableAmount_FullyCaptured_IsZero(): void
    {
        $dto = MolliePaymentDto::fromArray([
            'id' => 'tr_1',
            'status' => 'authorized',
            'amount' => ['currency' => 'EUR', 'value' => '100.00'],
            'amountCaptured' => '100.00',
        ]);

        self::assertSame(0.0, $dto->capturableAmount());
    }

    public function testCapturableAmount_NonAuthorizedPayment_IsZero(): void
    {
        $dto = MolliePaymentDto::fromArray([
            'id' => 'tr_1',
            'status' => 'paid',
            'amount' => ['currency' => 'EUR', 'value' => '100.00'],
            'amountCaptured' => '40.00',
        ]);

        self::assertSame(0.0, $dto->capturableAmount());
    }
}
