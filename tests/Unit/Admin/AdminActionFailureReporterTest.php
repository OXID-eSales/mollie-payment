<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionFailureReporter;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminActionFailureReporter::class)]
final class AdminActionFailureReporterTest extends TestCase
{
    private AdminValidationFeedbackInterface&MockObject $feedback;
    private LanguageTranslatorInterface&MockObject $translator;
    private AdminActionFailureReporter $reporter;

    protected function setUp(): void
    {
        $this->feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $this->translator = $this->createMock(LanguageTranslatorInterface::class);
        $this->reporter = new AdminActionFailureReporter($this->feedback, $this->translator);
    }

    public function testReport_Cancel_StoresTheTranslatedMessageWithTheDetailForTheOrder(): void
    {
        $this->translator->method('translateString')->with('MOLLIE_ADMIN_CANCEL_FAILED')
            ->willReturn('The authorization was not released: %s');
        $this->feedback->expects(self::once())->method('rejectWithMessage')
            ->with('order-1', 'The authorization was not released: Payment can no longer be released');

        $this->reporter->report($this->contract('order-1'), AdminActionFailureReporter::ACTION_CANCEL, 'Payment can no longer be released');
    }

    public function testReport_Capture_UsesTheCaptureKey(): void
    {
        $this->translator->method('translateString')->with('MOLLIE_ADMIN_CAPTURE_FAILED')->willReturn('Capture failed: %s');
        $this->feedback->expects(self::once())->method('rejectWithMessage')->with('order-2', 'Capture failed: only 30.00 is authorized');

        $this->reporter->report($this->contract('order-2'), AdminActionFailureReporter::ACTION_CAPTURE, 'only 30.00 is authorized');
    }

    public function testReport_WithoutTranslation_ShowsTheDetailNeverTheKey(): void
    {
        $this->translator->method('translateString')->willReturnArgument(0);
        $this->feedback->expects(self::once())->method('rejectWithMessage')->with('order-3', 'boom');

        $this->reporter->report($this->contract('order-3'), AdminActionFailureReporter::ACTION_CAPTURE, 'boom');
    }

    public function testReport_WithoutLinkedOrder_StoresNothing(): void
    {
        $this->feedback->expects(self::never())->method('rejectWithMessage');

        $this->reporter->report($this->contract(null), AdminActionFailureReporter::ACTION_CANCEL, 'boom');
    }

    private function contract(?string $orderId): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getOrderId')->willReturn($orderId);

        return $contract;
    }
}
