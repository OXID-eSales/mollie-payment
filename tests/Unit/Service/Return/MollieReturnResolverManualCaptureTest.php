<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service\Return;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\Return\MollieReturnResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mollie can accept `captureMode: manual` and capture anyway.
 *
 * Measured against the live API: Klarna sent with captureMode manual comes back
 * `authorized`; a CARD sent with the same flag comes back `paid` with no
 * authorizedAt. The merchant then finds no capture button, because the panel
 * correctly gates capture on the live status - and nothing says why.
 */
#[CoversClass(MollieReturnResolver::class)]
final class MollieReturnResolverManualCaptureTest extends TestCase
{
    private function resolver(
        MolliePaymentDto $payment,
        bool $manual,
        LoggerInterface $logger
    ): MollieReturnResolver {
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->method('getPayment')->willReturn($payment);

        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('isManualCapture')->willReturn($manual);

        return new MollieReturnResolver($adapter, new MollieStatusMapper(), $config, $logger);
    }

    private function payment(string $status, ?string $method): MolliePaymentDto
    {
        return new MolliePaymentDto(
            id: 'tr_test',
            status: $status,
            amount: MollieAmountDto::fromComponents('EUR', 10.0),
            method: $method,
        );
    }

    private function contract(): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_test');
        $contract->method('getAmount')->willReturn(10.0);
        $contract->method('getCurrency')->willReturn('EUR');

        return $contract;
    }

    private function resolve(MollieReturnResolver $resolver): void
    {
        $resolver->resolve($this->contract(), $this->createMock(EventContextInterface::class));
    }

    public function testWarnsWhenACardIsSettledDespiteManualCapture(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->resolve($this->resolver($this->payment('paid', 'creditcard'), true, $logger));
    }

    public function testSaysNothingWhenTheHoldWorked(): void
    {
        // Klarna honours it: authorized, so there IS something to capture.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->resolve($this->resolver($this->payment('authorized', 'klarna'), true, $logger));
    }

    public function testSaysNothingWhenAutomaticCaptureIsConfigured(): void
    {
        // A settled card is exactly what the merchant asked for here.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->resolve($this->resolver($this->payment('paid', 'creditcard'), false, $logger));
    }

    public function testSaysNothingForAMethodThatSettlesByDesign(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->resolve($this->resolver($this->payment('paid', 'paypal'), true, $logger));
    }
}
