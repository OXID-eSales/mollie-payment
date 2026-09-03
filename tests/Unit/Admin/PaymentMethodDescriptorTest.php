<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Payments\Mollie\Admin\PaymentMethodDescriptor;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 136 — what the customer actually paid with, as the Mollie panel shows
 * it. Mirrors the Stripe descriptor's contract so both panels behave the same
 * for an operator switching between orders.
 */
#[CoversClass(PaymentMethodDescriptor::class)]
#[Group('sprint-136')]
final class PaymentMethodDescriptorTest extends TestCase
{
    public function testNoPaymentIsUnknown(): void
    {
        $descriptor = PaymentMethodDescriptor::fromPayment(null);

        self::assertFalse($descriptor->isKnown());
        self::assertNull($descriptor->rawType);
        self::assertNull($descriptor->labelKey());
        self::assertNull($descriptor->detail());
    }

    public function testPaymentWithoutMethodIsUnknown(): void
    {
        $descriptor = PaymentMethodDescriptor::fromPayment($this->payment(null));

        self::assertFalse($descriptor->isKnown());
        self::assertNull($descriptor->labelKey());
    }

    public function testKlarnaVariantsShareOneLabel(): void
    {
        foreach (['klarna', 'klarnapaylater', 'klarnasliceit', 'klarnapaynow'] as $raw) {
            self::assertSame(
                'MOLLIE_PAYMENT_METHOD_KLARNA',
                PaymentMethodDescriptor::fromPayment($this->payment($raw))->labelKey(),
                $raw
            );
        }
    }

    public function testCreditCardCarriesBrandAndLast4(): void
    {
        $descriptor = PaymentMethodDescriptor::fromPayment(
            $this->payment('creditcard', 'Visa', '4242')
        );

        self::assertSame('MOLLIE_PAYMENT_METHOD_CREDITCARD', $descriptor->labelKey());
        self::assertSame('Visa •••• 4242', $descriptor->detail());
    }

    public function testWalletTakesTheLabelAndDemotesTheCard(): void
    {
        $descriptor = PaymentMethodDescriptor::fromPayment(
            $this->payment('creditcard', 'Mastercard', '0007', 'applepay')
        );

        self::assertSame('applepay', $descriptor->displayType());
        self::assertSame('MOLLIE_PAYMENT_METHOD_APPLE_PAY', $descriptor->labelKey());
        self::assertSame('Mastercard •••• 0007', $descriptor->detail());
    }

    public function testBrandOnlyAndLast4OnlyDetails(): void
    {
        self::assertSame(
            'Visa',
            PaymentMethodDescriptor::fromPayment($this->payment('creditcard', 'Visa'))->detail()
        );
        self::assertSame(
            '•••• 4242',
            PaymentMethodDescriptor::fromPayment($this->payment('creditcard', null, '4242'))->detail()
        );
    }

    public function testUnmappedMethodIsKnownButHasNoLabelKey(): void
    {
        $descriptor = PaymentMethodDescriptor::fromPayment($this->payment('some_new_method'));

        self::assertTrue($descriptor->isKnown());
        self::assertSame('some_new_method', $descriptor->displayType());
        self::assertNull($descriptor->labelKey());
    }

    private function payment(
        ?string $method,
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
