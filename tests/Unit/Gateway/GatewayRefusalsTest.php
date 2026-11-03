<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Gateway;

use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Mollie\Gateway\GatewayRefusals;
use OxidEsales\Payments\Mollie\Service\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How Mollie's money gateways say no to the admin: a translated sentence, never Mollie's own text (which carries
 * what the SDK put into it), and nothing recorded for the classic tab - payment-base's runner shows the refusal.
 */
final class GatewayRefusalsTest extends TestCase
{
    #[DataProvider('refusalCases')]
    public function testEachRefusalIsATranslatedSentenceWithItsOwnCode(string $method, string $code, string $key): void
    {
        $refusal = $this->refusals()->{$method}();

        self::assertSame($code, $refusal->refusalCode());
        self::assertSame('translated:' . $key, $refusal->getMessage());
        self::assertFalse($refusal->feedbackRecorded());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusalCases(): iterable
    {
        yield 'capture failed' => ['captureFailed', 'capture_failed', 'MOLLIE_ADMIN_CAPTURE_FAILED'];
        yield 'capture is final' => ['captureFinal', 'capture_final', 'MOLLIE_ADMIN_CAPTURE_FINAL'];
        yield 'refund failed' => ['refundFailed', 'refund_failed', 'MOLLIE_ADMIN_REFUND_FAILED'];
        yield 'refund while settling' => ['refundSettling', 'capture_settling', 'MOLLIE_ADMIN_REFUND_CAPTURE_SETTLING'];
        yield 'cancel failed' => ['cancelFailed', 'cancel_failed', 'MOLLIE_ADMIN_CANCEL_FAILED'];
        yield 'no Mollie payment' => ['noPayment', 'no_payment', 'MOLLIE_ADMIN_NO_PAYMENT'];
    }

    public function testTextIsTheFirstFailuresSentence(): void
    {
        $formatter = $this->createStub(MessageFormatterInterface::class);
        $formatter->method('format')->willReturnCallback(static fn (string $field, string $code): string => $field . ':' . $code);

        $refusal = $this->refusals($formatter)->text([
            new FieldValidationFailure('refundDescription', 'admin', 'blockedCharacter', ';', null),
            new FieldValidationFailure('refundDescription', 'admin', 'tooLong', null, null),
        ]);

        self::assertSame('blocked_character', $refusal->refusalCode());
        self::assertSame('refundDescription:blockedCharacter', $refusal->getMessage());
        self::assertFalse($refusal->feedbackRecorded());
    }

    public function testTheTranslatedSentencesExistInBothLanguages(): void
    {
        foreach (['en', 'de'] as $language) {
            $aLang = [];
            require dirname(__DIR__, 3) . "/views/admin_twig/{$language}/mollie_lang.php";
            foreach (['MOLLIE_ADMIN_CAPTURE_FINAL', 'MOLLIE_ADMIN_CANCEL_FAILED', 'MOLLIE_ADMIN_NO_PAYMENT'] as $ident) {
                self::assertNotSame('', trim((string) ($aLang[$ident] ?? '')), "$ident missing in $language");
            }
        }
    }

    private function refusals(?MessageFormatterInterface $formatter = null): GatewayRefusals
    {
        $translator = $this->createStub(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnCallback(static fn (string $key): string => 'translated:' . $key);

        return new GatewayRefusals($translator, $formatter ?? $this->createStub(MessageFormatterInterface::class));
    }
}
