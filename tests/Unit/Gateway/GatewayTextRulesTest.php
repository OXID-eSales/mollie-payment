<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Gateway;

use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Gateway\GatewayTextRules;
use OxidEsales\Payments\Mollie\Gateway\GatewayRefusals;
use OxidEsales\Payments\Mollie\Service\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\UserDataValidatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * MOL-15 - admin free text goes to Mollie with a capture or refund, so it runs through the checkout's character
 * rules (address kind `admin`). Kept for the money runner's gateways: the runner itself only checks length and
 * control characters.
 */
final class GatewayTextRulesTest extends TestCase
{
    public function testNoTextIsNothingToRefuse(): void
    {
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->expects(self::never())->method('validateFieldMap');

        self::assertNull($this->rules($validator)->refusalFor('captureReason', null));
    }

    public function testAcceptedTextIsNothingToRefuse(): void
    {
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->method('validateFieldMap')->with(['captureReason' => 'fine'], FieldValidationFailure::KIND_ADMIN)->willReturn([]);

        self::assertNull($this->rules($validator)->refusalFor('captureReason', 'fine'));
    }

    public function testRefusedTextIsTheSentenceOfItsFirstFailure(): void
    {
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->method('validateFieldMap')->willReturn([
            'captureReason' => new FieldValidationFailure('captureReason', 'admin', 'blockedCharacter', ';', null),
        ]);

        $refusal = $this->rules($validator)->refusalFor('captureReason', 'bad;');

        self::assertSame('blocked_character', $refusal?->refusalCode());
        self::assertSame('captureReason:blockedCharacter', $refusal->getMessage());
    }

    private function rules(UserDataValidatorInterface $validator): GatewayTextRules
    {
        $formatter = $this->createStub(MessageFormatterInterface::class);
        $formatter->method('format')->willReturnCallback(static fn (string $field, string $code): string => $field . ':' . $code);

        return new GatewayTextRules(
            $validator,
            new GatewayRefusals($this->createStub(LanguageTranslatorInterface::class), $formatter),
        );
    }
}
