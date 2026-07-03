<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Payments\Mollie\Admin\AdminAmountValidationMessageFormatter;
use OxidEsales\Payments\Mollie\Admin\AmountValidationResult;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminAmountValidationMessageFormatter::class)]
final class AdminAmountValidationMessageFormatterTest extends TestCase
{
    private LanguageTranslatorInterface&MockObject $translator;
    private AdminAmountValidationMessageFormatter $formatter;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(LanguageTranslatorInterface::class);
        $this->formatter = new AdminAmountValidationMessageFormatter($this->translator);
    }

    public function testGetPluginModuleId_ReturnsMollieModuleId(): void
    {
        self::assertSame(MollieDefinitions::MODULE_ID, $this->formatter->getPluginModuleId());
    }

    public function testFormat_ExceedsBound_TranslatesTheMatchingKey(): void
    {
        $this->translator->method('translateString')
            ->with('MOLLIE_ADMIN_AMOUNT_EXCEEDS_BOUND')
            ->willReturn('Amount exceeds the available balance.');

        $message = $this->formatter->format('refund_amount', AmountValidationResult::CODE_EXCEEDS_BOUND, null);

        self::assertSame('Amount exceeds the available balance.', $message);
    }

    public function testFormat_UnknownCode_FallsBackToGenericInvalidKey(): void
    {
        $this->translator->method('translateString')
            ->with('MOLLIE_ADMIN_AMOUNT_INVALID')
            ->willReturn('Amount is invalid.');

        $message = $this->formatter->format('capture_amount', 'someUnmappedCode', null);

        self::assertSame('Amount is invalid.', $message);
    }
}
