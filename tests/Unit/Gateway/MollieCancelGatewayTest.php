<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Gateway;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Payment\CancelGatewayInterface;
use OxidEsales\PaymentBase\Payment\CancelRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Gateway\GatewayRefusals;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Gateway\MollieCancelGateway;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * VR-22 A6 - the void port of payment-base's money runner, for Mollie: cancels the open authorization at Mollie and
 * books nothing; the contract follows Mollie's `canceled` webhook.
 */
final class MollieCancelGatewayTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $payments;
    private GatewayRefusals $refusals;

    protected function setUp(): void
    {
        $this->payments = $this->createMock(MolliePaymentsAdapterInterface::class);
        $translator = $this->createStub(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnCallback(static fn (string $key): string => 'translated:' . $key);
        $this->refusals = new GatewayRefusals($translator, $this->createStub(MessageFormatterInterface::class));
    }

    public function testItIsTheCancelGatewayOfMollie(): void
    {
        self::assertInstanceOf(CancelGatewayInterface::class, $this->gateway());
        self::assertSame(MollieDefinitions::PROVIDER_NAME, $this->gateway()->providerName());
    }

    public function testItCancelsThePaymentAtMollie(): void
    {
        $this->payments->expects(self::once())->method('cancelPayment')->with('tr_1')
            ->willReturn(new MolliePaymentDto('tr_1', 'canceled', MollieAmountDto::fromComponents('EUR', 100.0)));

        $this->gateway()->cancel($this->request());
    }

    public function testMollieSayingNoIsARefusalWithoutMolliesText(): void
    {
        $this->payments->method('cancelPayment')->willThrowException(new MollieAdapterException('secret detail'));
        try {
            $this->gateway()->cancel($this->request());
            self::fail('A refusal was expected');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('translated:MOLLIE_ADMIN_CANCEL_FAILED', $refusal->getMessage());
        }
    }

    public function testAContractWithoutAMolliePaymentIsRefused(): void
    {
        $this->payments->expects(self::never())->method('cancelPayment');

        try {
            $this->gateway()->cancel($this->request(null));
            self::fail('A refusal was expected');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('no_payment', $refusal->refusalCode());
        }
    }

    private function gateway(): MollieCancelGateway
    {
        return new MollieCancelGateway($this->payments, $this->refusals);
    }

    private function request(?string $paymentId = 'tr_1'): CancelRequest
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('c-1');
        $contract->method('getProviderOrderId')->willReturn($paymentId);

        return new CancelRequest($contract, 'idem-1', 'duplicate');
    }
}
