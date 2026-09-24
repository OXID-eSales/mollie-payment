<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Service\CheckoutUserDataGate;
use OxidEsales\Payments\Mollie\Service\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\UserDataValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CheckoutUserDataGate::class)]
final class CheckoutUserDataGateTest extends TestCase
{
    public function testNoProblemsForValidData(): void
    {
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->method('validateForUser')->willReturn([]);

        self::assertSame([], $this->gate($validator)->problemsFor($this->user()));
    }

    public function testNoProblemsWithoutAUser(): void
    {
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->expects(self::never())->method('validateForUser');

        self::assertSame([], $this->gate($validator)->problemsFor(null));
    }

    public function testFormatsEveryFailureOnceAndAppendsTheReviewNotice(): void
    {
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->method('validateForUser')->willReturn([
            new FieldValidationFailure('street', FieldValidationFailure::KIND_BILLING, 'blocked_character', ':', 'oxstreet'),
            new FieldValidationFailure('street', FieldValidationFailure::KIND_DELIVERY, 'blocked_character', '<', 'oxstreet'),
            new FieldValidationFailure('city', FieldValidationFailure::KIND_BILLING, 'disallowed_character', '!', 'oxcity'),
        ]);

        self::assertSame(
            ['msg:street', 'msg:city', 'Please review your address details.'],
            $this->gate($validator)->problemsFor($this->user()),
        );
    }

    public function testFailsOpenWhenTheValidatorCannotRun(): void
    {
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->method('validateForUser')->willThrowException(new RuntimeException('rules file gone'));

        self::assertSame([], $this->gate($validator)->problemsFor($this->user()));
    }

    private function gate(UserDataValidatorInterface $validator): CheckoutUserDataGate
    {
        $formatter = $this->createMock(MessageFormatterInterface::class);
        $formatter->method('format')->willReturnCallback(static fn (string $field) => 'msg:' . $field);
        $translator = $this->createMock(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnCallback(
            static fn (string $key) => $key === 'MOLLIE_VALIDATION_REVIEW_ADDRESS' ? 'Please review your address details.' : $key
        );

        return new CheckoutUserDataGate($validator, $formatter, $translator);
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getSelectedAddress')->willReturn(null);
        $user->method('getFieldData')->willReturn('');

        return $user;
    }
}
