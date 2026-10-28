<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentSnapshotProviderInterface;
use OxidEsales\Payments\Mollie\Admin\PaymentMethodLabelResolver;
use OxidEsales\Payments\Mollie\Admin\ReleaseRule;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MolliePaymentDetails;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MolliePaymentDetailsReader;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieUrlBuilder;
use PHPUnit\Framework\TestCase;

final class MolliePaymentDetailsReaderTest extends TestCase
{
    private ?MolliePaymentDto $payment;
    private int $reads = 0;
    private bool $authorizedHold = true;
    private float $captureBound = 60.0;
    private bool $multiCapture = true;

    protected function setUp(): void
    {
        $this->payment = new MolliePaymentDto(
            'tr_abc',
            'authorized',
            new MollieAmountDto('EUR', 100.0),
            method: 'creditcard',
            cardBrand: 'Visa',
            cardLast4: '4242',
            amountCaptured: 40.0,
        );
    }

    public function testForContract_ReadsMollieOnceAndAnswersMethodStatusAndReleaseFigures(): void
    {
        $details = $this->reader($this->contract())->forContract('c1');

        self::assertSame(1, $this->reads);
        self::assertSame('tr_abc', $details?->paymentId);
        self::assertSame('https://my.mollie.com/dashboard/payments/tr_abc', $details->dashboardUrl);
        self::assertSame('Credit card', $details->methodLabel);
        self::assertSame('Visa •••• 4242', $details->methodDetail);
        self::assertSame('authorized', $details->mollieStatus);
        self::assertSame(60.0, $details->amountReleasable);
        self::assertSame(0.0, $details->amountReleased, 'nothing was released yet');
        self::assertTrue($details->multiCapture);
        self::assertNull($details->apiError);
    }

    public function testForContract_AfterARelease_ShowsWhatWentBack(): void
    {
        $this->authorizedHold = false;
        $this->payment = new MolliePaymentDto('tr_abc', 'paid', new MollieAmountDto('EUR', 100.0), method: 'creditcard', amountCaptured: 40.0);

        $details = $this->reader($this->contract(fulfilled: true))->forContract('c1');

        self::assertSame(60.0, $details?->amountReleased);
        self::assertSame(0.0, $details->amountReleasable);
    }

    public function testForContract_MollieCannotBeRead_FailsClosedKeepingOnlyTheShopsOwnRecord(): void
    {
        $this->payment = null;

        $details = $this->reader($this->contract())->forContract('c1');

        self::assertSame(MolliePaymentDetails::ERROR_UNREACHABLE, $details?->apiError);
        self::assertSame('tr_abc', $details->paymentId);
        self::assertNotNull($details->dashboardUrl);
        self::assertNull($details->methodLabel);
        self::assertNull($details->methodDetail);
        self::assertNull($details->mollieStatus);
        self::assertNull($details->amountReleasable, 'a figure that could not be read is not 0');
    }

    public function testForContract_NoMolliePaymentYet_IsNoPaymentAndMollieIsNotAsked(): void
    {
        $details = $this->reader($this->contract(providerOrderId: null))->forContract('c1');

        self::assertSame(MolliePaymentDetails::ERROR_NO_PAYMENT, $details?->apiError);
        self::assertNull($details->paymentId);
        self::assertNull($details->dashboardUrl);
        self::assertSame(0, $this->reads);
    }

    public function testForContract_AnUnknownContractAndAContractOfAnotherProvider_AreNull(): void
    {
        self::assertNull($this->reader(null)->forContract('missing'));
        self::assertNull($this->reader($this->contract(provider: 'stripe'))->forContract('c1'));
        self::assertSame(0, $this->reads);
    }

    public function testForContract_AnUnknownMethodHasNoLabel(): void
    {
        $this->payment = new MolliePaymentDto('tr_abc', 'open', new MollieAmountDto('EUR', 100.0));

        $details = $this->reader($this->contract())->forContract('c1');

        self::assertNull($details?->methodLabel);
        self::assertSame('open', $details->mollieStatus);
    }

    private function contract(?string $providerOrderId = 'tr_abc', string $provider = 'mollie', bool $fulfilled = false): PaymentContractInterface
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getProvider')->willReturn($provider);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn(40.0);
        $contract->method('getState')->willReturn($fulfilled ? ContractState::fulfilled() : ContractState::committed());

        return $contract;
    }

    private function reader(?PaymentContractInterface $contract): MolliePaymentDetailsReader
    {
        $contracts = $this->createStub(ContractRepositoryInterface::class);
        $contracts->method('findById')->willReturn($contract);
        $snapshots = $this->createStub(MolliePaymentSnapshotProviderInterface::class);
        $snapshots->method('snapshot')->willReturnCallback(function (): ?MolliePaymentDto {
            ++$this->reads;

            return $this->payment;
        });
        $bounds = $this->createStub(AdminActionBoundsInterface::class);
        $bounds->method('isAuthorizedHold')->willReturnCallback(fn (): bool => $this->authorizedHold);
        $bounds->method('captureBound')->willReturnCallback(fn (): float => $this->captureBound);
        $translator = $this->createStub(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnCallback(
            static fn (string $key): string => $key === 'MOLLIE_PAYMENT_METHOD_CREDITCARD' ? 'Credit card' : $key
        );
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);
        $config->method('isMultiCaptureEnabled')->willReturnCallback(fn (): bool => $this->multiCapture);

        return new MolliePaymentDetailsReader(
            $contracts,
            $snapshots,
            new PaymentMethodLabelResolver($translator),
            new ReleaseRule($bounds),
            $config,
            new MollieUrlBuilder(),
        );
    }
}
