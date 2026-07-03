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
use OxidEsales\Payments\Mollie\Adapter\LazyMollieAdapter;
use OxidEsales\Payments\Mollie\Adapter\MollieAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LazyMollieAdapter::class)]
final class LazyMollieAdapterTest extends TestCase
{
    public function testDoesNotBuildClientUntilFirstMethodCall(): void
    {
        $calls = 0;
        new LazyMollieAdapter(function () use (&$calls): MollieAdapter {
            $calls++;
            return $this->realAdapter();
        });

        self::assertSame(0, $calls, 'The adapter factory must not run at construction');
    }

    public function testReusesSingleClientAcrossCalls(): void
    {
        $calls = 0;
        $lazy = new LazyMollieAdapter(function () use (&$calls): MollieAdapter {
            $calls++;
            return $this->realAdapter();
        });

        $lazy->getPayment('tr_1');
        $lazy->getPayment('tr_2');

        self::assertSame(1, $calls, 'The underlying adapter must be built exactly once and reused');
    }

    private function realAdapter(): MollieAdapter
    {
        $client = $this->createMock(MollieApiClient::class);
        $payments = $this->createMock(PaymentEndpoint::class);
        $payments->method('get')->willReturnCallback(function (): Payment {
            $payment = new Payment($this->createMock(MollieApiClient::class));
            $payment->id = 'tr_1';
            $payment->status = 'open';
            $payment->amount = (object) ['currency' => 'EUR', 'value' => '10.00'];
            return $payment;
        });
        $client->payments = $payments;

        return new MollieAdapter($client);
    }
}
