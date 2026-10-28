<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionRefusals;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\Service\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use PHPUnit\Framework\TestCase;

final class AdminActionRefusalsTest extends TestCase
{
    public function testAmount_IsRecordedForTheClassicTabAndRefusedWithTheSameSentence(): void
    {
        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::once())->method('reject')->with('o1', 'refund_amount', 'amountExceedsBound')
            ->willReturn('Too much');

        $refusal = $this->refusals($feedback)->amount('o1', 'refund_amount', 'amountExceedsBound');

        self::assertSame('amount_exceeds_bound', $refusal->refusalCode());
        self::assertSame('Too much', $refusal->getMessage());
        self::assertTrue($refusal->feedbackRecorded());
    }

    public function testText_EveryFailureIsRecordedAndTheFirstIsTheSentence(): void
    {
        $recorded = [];
        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::exactly(2))->method('rejectWithMessage')->willReturnCallback(
            static function (string $orderId, string $message) use (&$recorded): void {
                $recorded[] = [$orderId, $message];
            }
        );
        $formatter = $this->createStub(MessageFormatterInterface::class);
        $formatter->method('format')->willReturnCallback(static fn (string $field, string $code): string => $field . ':' . $code);

        $refusal = $this->refusals($feedback, $formatter)->text('o1', [
            new FieldValidationFailure('captureReason', 'admin', 'blocked_character', ';', null),
            new FieldValidationFailure('captureReason', 'admin', 'too_long', null, null),
        ]);

        self::assertSame([['o1', 'captureReason:blocked_character'], ['o1', 'captureReason:too_long']], $recorded);
        self::assertSame('blocked_character', $refusal->refusalCode());
        self::assertSame('captureReason:blocked_character', $refusal->getMessage());
        self::assertTrue($refusal->feedbackRecorded());
    }

    public function testNotReleasable_NothingWasRecordedSoTheClassicTabMustShowIt(): void
    {
        $refusal = $this->refusals()->notReleasable();

        self::assertSame('not_releasable', $refusal->refusalCode());
        self::assertSame('translated:MOLLIE_ADMIN_RELEASE_NOT_POSSIBLE', $refusal->getMessage());
        self::assertFalse($refusal->feedbackRecorded());
    }

    public function testReleaseFailed_TheHandlerRecordedItAlready(): void
    {
        $refusal = $this->refusals()->releaseFailed();

        self::assertSame('release_failed', $refusal->refusalCode());
        self::assertSame('translated:MOLLIE_ADMIN_RELEASE_FAILED', $refusal->getMessage());
        self::assertTrue($refusal->feedbackRecorded());
    }

    public function testTheTranslatedSentencesExistInBothLanguages(): void
    {
        foreach (['en', 'de'] as $language) {
            $aLang = [];
            require dirname(__DIR__, 3) . "/views/admin_twig/{$language}/mollie_lang.php";
            foreach (['MOLLIE_ADMIN_RELEASE_NOT_POSSIBLE', 'MOLLIE_ADMIN_RELEASE_FAILED'] as $ident) {
                self::assertNotSame('', trim((string) ($aLang[$ident] ?? '')), "$ident missing in $language");
            }
        }
    }

    private function refusals(?AdminValidationFeedbackInterface $feedback = null, ?MessageFormatterInterface $formatter = null): AdminActionRefusals
    {
        $translator = $this->createStub(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnCallback(static fn (string $key): string => 'translated:' . $key);

        return new AdminActionRefusals(
            $feedback ?? $this->createStub(AdminValidationFeedbackInterface::class),
            $formatter ?? $this->createStub(MessageFormatterInterface::class),
            $translator,
        );
    }
}
