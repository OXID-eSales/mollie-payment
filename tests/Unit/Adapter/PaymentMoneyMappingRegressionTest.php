<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter;

use Mollie\Api\Endpoints\PaymentEndpoint;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Sprint 11 Story 4 (F7) — every money field on {@see MolliePaymentDto} must actually be mapped
 * from the SDK payment.
 *
 * `MollieAdapter::mapPayment()` used to call `getAmountRefunded()` and `getAmountRemaining()` but
 * NOT `getAmountChargedBack()`, and the DTO's `= 0.0` constructor default silently supplied a
 * plausible-looking zero. Consequences were real money: the chargeback routing branch in
 * `MollieWebhookProcessor::determineEventType()` was dead, and `refundableAmount()` — which
 * subtracts `amountChargedBack` — overstated the refundable ceiling by exactly the charged-back
 * amount, so an admin could refund money the customer had already clawed back.
 *
 * Making the constructor parameter required would only have forced an author to *type* a value;
 * it would not have stopped them typing `0.0`. This test is the stronger guard: it feeds the
 * adapter an SDK payment whose money fields all carry DISTINCT non-zero values and asserts each
 * one arrives intact, so a dropped mapping (or a mapping crossed with the wrong accessor) fails
 * here. The reflection assertion additionally fails when a new money field appears on the DTO
 * without being added to this test.
 */
#[CoversClass(MollieAdapter::class)]
final class PaymentMoneyMappingRegressionTest extends TestCase
{
    /**
     * Distinct values on purpose — a copy/paste between accessors cannot pass unnoticed.
     */
    private const AMOUNT = 100.00;
    private const REFUNDED = 5.00;
    private const REMAINING = 7.00;
    private const CHARGED_BACK = 40.00;

    private MollieApiClient $client;
    private PaymentEndpoint $payments;

    protected function setUp(): void
    {
        $this->client = $this->createMock(MollieApiClient::class);
        $this->payments = $this->createMock(PaymentEndpoint::class);
        $this->client->payments = $this->payments;
    }

    public function testEveryMoneyFieldIsMappedFromTheSdkPayment(): void
    {
        $this->payments->method('get')->willReturn($this->sdkPaymentWithAllMoneyFields());

        $dto = (new MollieAdapter($this->client))->getPayment('tr_money');

        self::assertSame(self::AMOUNT, $dto->amount->value, 'amount');
        self::assertSame(self::REFUNDED, $dto->amountRefunded, 'amountRefunded');
        self::assertSame(self::REMAINING, $dto->amountRemaining, 'amountRemaining');
        self::assertSame(self::CHARGED_BACK, $dto->amountChargedBack, 'amountChargedBack');
    }

    public function testAmountChargedBackSurvivesTheWebhookVerificationFetch(): void
    {
        // fetchByWebhookId() is the webhook's verification round-trip; a chargeback that does not
        // survive it leaves MollieWebhookProcessor::determineEventType()'s `chargedback` branch dead.
        $this->payments->method('get')->willReturn($this->sdkPaymentWithAllMoneyFields());

        $dto = (new MollieAdapter($this->client))->fetchByWebhookId('tr_money');

        self::assertGreaterThan(0.0, $dto->amountChargedBack);
    }

    public function testRefundableAmountAccountsForTheChargeback(): void
    {
        $this->payments->method('get')->willReturn($this->sdkPaymentWithAllMoneyFields());

        $dto = (new MollieAdapter($this->client))->getPayment('tr_money');

        // 100.00 − 5.00 refunded − 40.00 charged back
        self::assertSame(55.00, $dto->refundableAmount());
    }

    public function testMoneyFieldInventoryIsUnchanged(): void
    {
        $moneyFields = [];
        foreach ((new ReflectionClass(MolliePaymentDto::class))->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === 'float') {
                $moneyFields[] = $property->getName();
            }
        }
        sort($moneyFields);

        self::assertSame(
            ['amountChargedBack', 'amountRefunded', 'amountRemaining'],
            $moneyFields,
            'A float field was added to or removed from MolliePaymentDto. Add it to this test and '
            . 'make sure MollieAdapter::mapPayment() maps it — a constructor default will otherwise '
            . 'hide the omission (F7).',
        );
    }

    private function sdkPaymentWithAllMoneyFields(): Payment
    {
        $payment = new Payment($this->client);
        $payment->id = 'tr_money';
        $payment->status = 'paid';
        $payment->amount = (object) ['currency' => 'EUR', 'value' => '100.00'];
        $payment->amountRefunded = (object) ['currency' => 'EUR', 'value' => '5.00'];
        $payment->amountRemaining = (object) ['currency' => 'EUR', 'value' => '7.00'];
        $payment->amountChargedBack = (object) ['currency' => 'EUR', 'value' => '40.00'];
        $payment->createdAt = '2026-08-12T10:00:00+00:00';

        return $payment;
    }
}
