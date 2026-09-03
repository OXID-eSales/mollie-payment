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
use OxidEsales\Payments\Mollie\Adapter\MollieAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 136 Story 4 — the admin panel's "payment method used" row needs the
 * method Mollie reports plus, for card payments, the brand and last four
 * digits the customer will quote on the phone.
 *
 * `method` was already mapped and had no consumer; `details` was dropped
 * entirely.
 */
#[CoversClass(MollieAdapter::class)]
#[Group('sprint-136')]
final class PaymentMethodDetailsMappingTest extends TestCase
{
    private MollieApiClient $client;
    private PaymentEndpoint $payments;

    protected function setUp(): void
    {
        $this->client = $this->createMock(MollieApiClient::class);
        $this->payments = $this->createMock(PaymentEndpoint::class);
        $this->client->payments = $this->payments;
    }

    public function testCreditCardBrandAndLast4AreMapped(): void
    {
        $payment = $this->payment('creditcard');
        $payment->details = (object) [
            'cardLabel'  => 'Visa',
            'cardNumber' => '**** **** **** 4242',
            'cardHolder' => 'A Customer',
        ];

        $dto = $this->fetch($payment);

        self::assertSame('creditcard', $dto->method);
        self::assertSame('Visa', $dto->cardBrand);
        self::assertSame('4242', $dto->cardLast4);
        self::assertNull($dto->walletType);
    }

    public function testWalletIsMapped(): void
    {
        // Mollie reports an Apple Pay payment as method=creditcard with a wallet
        // marker in details — the panel must name the wallet, not the card.
        $payment = $this->payment('creditcard');
        $payment->details = (object) [
            'cardLabel'  => 'Mastercard',
            'cardNumber' => '**** **** **** 0007',
            'wallet'     => 'applepay',
        ];

        $dto = $this->fetch($payment);

        self::assertSame('applepay', $dto->walletType);
        self::assertSame('Mastercard', $dto->cardBrand);
        self::assertSame('0007', $dto->cardLast4);
    }

    public function testCardNumberWithoutSeparatorsStillYieldsLast4(): void
    {
        $payment = $this->payment('creditcard');
        $payment->details = (object) ['cardNumber' => 'xxxxxxxxxxxx1234'];

        self::assertSame('1234', $this->fetch($payment)->cardLast4);
    }

    public function testMethodWithoutCardDetailsMapsToNulls(): void
    {
        $payment = $this->payment('klarna');
        $payment->details = (object) ['consumerName' => 'A Customer'];

        $dto = $this->fetch($payment);

        self::assertSame('klarna', $dto->method);
        self::assertNull($dto->cardBrand);
        self::assertNull($dto->cardLast4);
        self::assertNull($dto->walletType);
    }

    public function testAbsentDetailsObjectMapsToNulls(): void
    {
        $dto = $this->fetch($this->payment('ideal'));

        self::assertSame('ideal', $dto->method);
        self::assertNull($dto->cardBrand);
        self::assertNull($dto->cardLast4);
        self::assertNull($dto->walletType);
    }

    public function testMaskOnlyCardNumberYieldsNoLast4(): void
    {
        // Never present the mask itself as digits the customer can confirm.
        $payment = $this->payment('creditcard');
        $payment->details = (object) ['cardNumber' => '**** **** **** ****'];

        self::assertNull($this->fetch($payment)->cardLast4);
    }

    private function fetch(Payment $payment): \OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto
    {
        $this->payments->method('get')->willReturn($payment);

        return (new MollieAdapter($this->client))->getPayment((string) $payment->id);
    }

    private function payment(string $method): Payment
    {
        $payment = new Payment($this->client);
        $payment->id = 'tr_' . $method;
        $payment->status = 'paid';
        $payment->method = $method;
        $payment->amount = (object) ['currency' => 'EUR', 'value' => '100.00'];
        $payment->createdAt = '2026-08-27T10:00:00+00:00';

        return $payment;
    }
}
