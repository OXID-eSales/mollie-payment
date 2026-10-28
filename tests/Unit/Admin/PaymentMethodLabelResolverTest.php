<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Admin\PaymentMethodDescriptor;
use OxidEsales\Payments\Mollie\Admin\PaymentMethodLabelResolver;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use PHPUnit\Framework\TestCase;

final class PaymentMethodLabelResolverTest extends TestCase
{
    public function testLabel_IsTheTranslatedMethodName(): void
    {
        $resolver = new PaymentMethodLabelResolver($this->translator(['MOLLIE_PAYMENT_METHOD_IDEAL' => 'iDEAL']));

        self::assertSame('iDEAL', $resolver->label($this->descriptor('ideal')));
    }

    public function testLabel_WithoutATranslation_FallsBackToTheRawCodeNeverTheIdent(): void
    {
        $resolver = new PaymentMethodLabelResolver($this->translator([]));

        self::assertSame('ideal', $resolver->label($this->descriptor('ideal')));
    }

    public function testLabel_AnUnknownMethodIsEmpty(): void
    {
        self::assertSame('', (new PaymentMethodLabelResolver($this->translator([])))->label($this->descriptor(null)));
    }

    /**
     * @param array<string, string> $translations
     */
    private function translator(array $translations): LanguageTranslatorInterface
    {
        $translator = $this->createStub(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnCallback(static fn (string $key): string => $translations[$key] ?? $key);

        return $translator;
    }

    private function descriptor(?string $method): PaymentMethodDescriptor
    {
        return PaymentMethodDescriptor::fromPayment($method === null ? null : new MolliePaymentDto('tr_1', 'paid', new MollieAmountDto('EUR', 1.0), method: $method));
    }
}
