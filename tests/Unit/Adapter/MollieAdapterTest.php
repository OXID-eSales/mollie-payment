<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter;

use Mollie\Api\Endpoints\MethodEndpoint;
use Mollie\Api\Endpoints\PaymentCaptureEndpoint;
use Mollie\Api\Endpoints\PaymentEndpoint;
use Mollie\Api\Endpoints\PaymentRefundEndpoint;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Capture;
use Mollie\Api\Resources\CaptureCollection;
use Mollie\Api\Resources\Method;
use Mollie\Api\Resources\MethodCollection;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use Mollie\Api\Resources\RefundCollection;
use OxidEsales\Payments\Mollie\Adapter\Dto\CaptureRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MethodsListRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAddressDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieLineDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;
use OxidEsales\Payments\Mollie\Adapter\Exception\CaptureNotSupportedException;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MollieAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieAdapter::class)]
final class MollieAdapterTest extends TestCase
{
    private MollieApiClient $client;
    private PaymentEndpoint $payments;
    private PaymentRefundEndpoint $refunds;
    private PaymentCaptureEndpoint $captures;
    private MethodEndpoint $methods;

    protected function setUp(): void
    {
        $this->client = $this->createMock(MollieApiClient::class);
        $this->payments = $this->createMock(PaymentEndpoint::class);
        $this->refunds = $this->createMock(PaymentRefundEndpoint::class);
        $this->captures = $this->createMock(PaymentCaptureEndpoint::class);
        $this->methods = $this->createMock(MethodEndpoint::class);
        $this->client->methods = $this->methods;
        $this->client->payments = $this->payments;
        $this->client->paymentRefunds = $this->refunds;
        $this->client->paymentCaptures = $this->captures;
    }

    public function testCreatePayment_SendsAmountMethodRedirectAndWebhookUrls(): void
    {
        $captured = [];
        $this->payments->method('create')->willReturnCallback(
            function (array $data) use (&$captured): Payment {
                $captured = $data;
                return $this->payment(['id' => 'tr_1', 'status' => 'open']);
            },
        );

        (new MollieAdapter($this->client))->createPayment(new CreatePaymentRequest(
            new MollieAmountDto('EUR', 24.0),
            'Order 4711',
            'https://shop.test/return',
            'https://shop.test/webhook',
            'ideal',
            ['order_number' => '4711'],
        ));

        self::assertSame(['currency' => 'EUR', 'value' => '24.00'], $captured['amount']);
        self::assertSame('Order 4711', $captured['description']);
        self::assertSame('https://shop.test/return', $captured['redirectUrl']);
        self::assertSame('https://shop.test/webhook', $captured['webhookUrl']);
        self::assertSame('ideal', $captured['method']);
        self::assertSame(['order_number' => '4711'], $captured['metadata']);
    }

    public function testCreatePayment_WithCardToken_ForcesCreditcardMethodAndSendsToken(): void
    {
        $captured = [];
        $this->payments->method('create')->willReturnCallback(
            function (array $data) use (&$captured): Payment {
                $captured = $data;
                return $this->payment(['id' => 'tr_cc', 'status' => 'open']);
            },
        );

        (new MollieAdapter($this->client))->createPayment(new CreatePaymentRequest(
            amount: new MollieAmountDto('EUR', 24.0),
            description: 'Order 4711',
            redirectUrl: 'https://shop.test/return',
            method: null,
            cardToken: 'tkn_abc123',
        ));

        // IFRAME-04: a card token pins the method to creditcard and attaches the token.
        self::assertSame('creditcard', $captured['method']);
        self::assertSame('tkn_abc123', $captured['cardToken']);
    }

    public function testCreatePayment_WithoutCardToken_OmitsCardTokenKey(): void
    {
        $captured = [];
        $this->payments->method('create')->willReturnCallback(
            function (array $data) use (&$captured): Payment {
                $captured = $data;
                return $this->payment(['id' => 'tr_1', 'status' => 'open']);
            },
        );

        (new MollieAdapter($this->client))->createPayment(new CreatePaymentRequest(
            amount: new MollieAmountDto('EUR', 10.0),
            description: 'Order',
            redirectUrl: 'https://shop.test/return',
        ));

        self::assertArrayNotHasKey('cardToken', $captured);
    }

    public function testCreatePayment_WithAddressAndLines_IncludesThemInTheBody(): void
    {
        $captured = [];
        $this->payments->method('create')->willReturnCallback(
            function (array $data) use (&$captured): Payment {
                $captured = $data;
                return $this->payment(['id' => 'tr_k', 'status' => 'open']);
            },
        );

        $address = new MollieAddressDto('Marc', 'Muster', 'm@x.test', 'Street 1', '12345', 'City', 'DE');
        $line = new MollieLineDto('Item', 1, new MollieAmountDto('EUR', 10.0), new MollieAmountDto('EUR', 10.0), 19.0, new MollieAmountDto('EUR', 1.6));

        (new MollieAdapter($this->client))->createPayment(new CreatePaymentRequest(
            amount: new MollieAmountDto('EUR', 10.0),
            description: 'Order',
            redirectUrl: 'https://shop.test/return',
            method: 'klarna',
            billingAddress: $address,
            shippingAddress: $address,
            lines: [$line],
        ));

        self::assertSame('DE', $captured['billingAddress']['country']);
        self::assertSame('m@x.test', $captured['billingAddress']['email']);
        self::assertSame($captured['billingAddress'], $captured['shippingAddress']);
        self::assertCount(1, $captured['lines']);
        self::assertSame('10.00', $captured['lines'][0]['totalAmount']['value']);
        self::assertSame('19.00', $captured['lines'][0]['vatRate']);
    }

    public function testCreatePayment_WithoutAddressOrLines_OmitsThoseKeys(): void
    {
        $captured = [];
        $this->payments->method('create')->willReturnCallback(
            function (array $data) use (&$captured): Payment {
                $captured = $data;
                return $this->payment(['id' => 'tr_1', 'status' => 'open']);
            },
        );

        (new MollieAdapter($this->client))->createPayment(new CreatePaymentRequest(
            amount: new MollieAmountDto('EUR', 10.0),
            description: 'Order',
            redirectUrl: 'https://shop.test/return',
        ));

        self::assertArrayNotHasKey('billingAddress', $captured);
        self::assertArrayNotHasKey('lines', $captured);
    }

    public function testCreatePayment_ReturnsDtoWithCheckoutUrl(): void
    {
        $this->payments->method('create')->willReturn($this->payment([
            'id' => 'tr_1',
            'status' => 'open',
            'checkout' => 'https://www.mollie.com/checkout/tr_1',
        ]));

        $dto = (new MollieAdapter($this->client))->createPayment(new CreatePaymentRequest(
            new MollieAmountDto('EUR', 10.0),
            'Order',
            'https://shop.test/return',
        ));

        self::assertSame('tr_1', $dto->id);
        self::assertSame('https://www.mollie.com/checkout/tr_1', $dto->checkoutUrl);
    }

    public function testCreateRefund_PartialAmount_SendsCorrectBody(): void
    {
        $captured = [];
        $this->refunds->method('createForId')->willReturnCallback(
            function (string $paymentId, array $data) use (&$captured): Refund {
                $captured = ['paymentId' => $paymentId] + $data;
                return $this->refund();
            },
        );

        $dto = (new MollieAdapter($this->client))->createRefund(new RefundRequest(
            'tr_abc',
            new MollieAmountDto('EUR', 3.5),
            null, // reason
            null, // idempotencyKey
            'partial refund', // description
        ));

        self::assertSame('tr_abc', $captured['paymentId']);
        self::assertSame(['currency' => 'EUR', 'value' => '3.50'], $captured['amount']);
        self::assertSame('partial refund', $captured['description']);
        self::assertSame('re_1', $dto->id);
    }

    public function testCancelPayment_DelegatesToSdk(): void
    {
        $this->payments->expects(self::once())->method('cancel')->with('tr_abc')
            ->willReturn($this->payment(['id' => 'tr_abc', 'status' => 'canceled']));

        $dto = (new MollieAdapter($this->client))->cancelPayment('tr_abc');
        self::assertSame('canceled', $dto->status);
    }

    public function testCreateCapture_OnNonCapturableMethod_ThrowsCaptureNotSupported(): void
    {
        $this->payments->method('get')->willReturn($this->payment(['id' => 'tr_abc', 'status' => 'paid']));
        $this->captures->expects(self::never())->method('createForId');

        $this->expectException(CaptureNotSupportedException::class);
        (new MollieAdapter($this->client))->createCapture(new CaptureRequest('tr_abc'));
    }

    public function testCreateCapture_AuthorizedPayment_CallsCreateForId(): void
    {
        $this->payments->method('get')->willReturn($this->payment(['id' => 'tr_abc', 'status' => 'authorized']));
        $this->captures->expects(self::once())->method('createForId')->with('tr_abc')
            ->willReturn($this->capture());

        $dto = (new MollieAdapter($this->client))->createCapture(new CaptureRequest('tr_abc'));
        self::assertSame('cpt_1', $dto->id);
    }

    public function testSdkException_IsConvertedToDomainException(): void
    {
        $this->payments->method('get')->willThrowException(new ApiException('boom'));

        $this->expectException(MollieAdapterException::class);
        (new MollieAdapter($this->client))->getPayment('tr_missing');
    }

    public function testListRefunds_MapsEachRefundInTheCollection(): void
    {
        $collection = new RefundCollection($this->client, 1, null);
        $collection[] = $this->refund();
        $this->refunds->method('listForId')->with('tr_abc')->willReturn($collection);

        $dtos = (new MollieAdapter($this->client))->listRefunds('tr_abc');

        self::assertCount(1, $dtos);
        self::assertSame('re_1', $dtos[0]->id);
        self::assertSame('2026-07-08T11:00:00+00:00', $dtos[0]->createdAt);
    }

    public function testListRefunds_OnSdkException_ThrowsDomainException(): void
    {
        $this->refunds->method('listForId')->willThrowException(new ApiException('boom'));

        $this->expectException(MollieAdapterException::class);
        (new MollieAdapter($this->client))->listRefunds('tr_abc');
    }

    public function testListCaptures_MapsEachCaptureInTheCollection(): void
    {
        $collection = new CaptureCollection($this->client, 1, null);
        $collection[] = $this->capture();
        $this->captures->method('listForId')->with('tr_abc')->willReturn($collection);

        $dtos = (new MollieAdapter($this->client))->listCaptures('tr_abc');

        self::assertCount(1, $dtos);
        self::assertSame('cpt_1', $dtos[0]->id);
        self::assertSame('2026-07-08T12:00:00+00:00', $dtos[0]->createdAt);
    }

    public function testListCaptures_OnSdkException_ThrowsDomainException(): void
    {
        $this->captures->method('listForId')->willThrowException(new ApiException('boom'));

        $this->expectException(MollieAdapterException::class);
        (new MollieAdapter($this->client))->listCaptures('tr_abc');
    }

    public function testListActiveMethods_MapsEachMethodInTheCollection(): void
    {
        $collection = new MethodCollection(1, null);
        $collection[] = $this->method();
        $this->methods->method('allActive')->willReturn($collection);

        $dtos = (new MollieAdapter($this->client))->listActiveMethods(new MethodsListRequest());

        self::assertCount(1, $dtos);
        self::assertSame('ideal', $dtos[0]->id);
        self::assertSame('iDEAL', $dtos[0]->description);
    }

    public function testListActiveMethods_PassesBillingCountryAndLocaleAsFilters(): void
    {
        $captured = [];
        $this->methods->method('allActive')->willReturnCallback(
            function (array $parameters) use (&$captured): MethodCollection {
                $captured = $parameters;
                return new MethodCollection(0, null);
            },
        );

        (new MollieAdapter($this->client))->listActiveMethods(new MethodsListRequest('NL', 'nl_NL'));

        self::assertSame('NL', $captured['billingCountry']);
        self::assertSame('nl_NL', $captured['locale']);
    }

    public function testListActiveMethods_OnSdkException_ThrowsDomainException(): void
    {
        $this->methods->method('allActive')->willThrowException(new ApiException('boom'));

        $this->expectException(MollieAdapterException::class);
        (new MollieAdapter($this->client))->listActiveMethods(new MethodsListRequest());
    }

    /**
     * @param array{id?: string, status?: string, checkout?: string} $props
     */
    private function payment(array $props): Payment
    {
        $payment = new Payment($this->client);
        $payment->id = $props['id'] ?? 'tr_1';
        $payment->status = $props['status'] ?? 'open';
        $payment->amount = (object) ['currency' => 'EUR', 'value' => '24.00'];
        $payment->metadata = (object) ['order_number' => '4711'];
        $payment->createdAt = '2026-07-08T10:00:00+00:00';
        if (isset($props['checkout'])) {
            $payment->_links = (object) ['checkout' => (object) ['href' => $props['checkout']]];
        }
        return $payment;
    }

    private function refund(): Refund
    {
        $refund = new Refund($this->client);
        $refund->id = 're_1';
        $refund->paymentId = 'tr_abc';
        $refund->amount = (object) ['currency' => 'EUR', 'value' => '3.50'];
        $refund->status = 'pending';
        $refund->createdAt = '2026-07-08T11:00:00+00:00';
        return $refund;
    }

    private function capture(): Capture
    {
        $capture = new Capture($this->client);
        $capture->id = 'cpt_1';
        $capture->paymentId = 'tr_abc';
        $capture->amount = (object) ['currency' => 'EUR', 'value' => '24.00'];
        $capture->status = 'pending';
        $capture->createdAt = '2026-07-08T12:00:00+00:00';
        return $capture;
    }

    private function method(): Method
    {
        $method = new Method($this->client);
        $method->id = 'ideal';
        $method->description = 'iDEAL';
        return $method;
    }
}
