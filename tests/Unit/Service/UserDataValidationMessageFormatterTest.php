<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\UserDataValidationMessageFormatter;
use OxidEsales\Payments\Mollie\Service\ValidationRulesProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserDataValidationMessageFormatter::class)]
final class UserDataValidationMessageFormatterTest extends TestCase
{
    public function testGetPluginModuleIdReturnsMollieModuleId(): void
    {
        $formatter = $this->formatter();

        self::assertSame(MollieDefinitions::MODULE_ID, $formatter->getPluginModuleId());
    }

    public function testFormatFallsBackToRawFieldNameWhenLabelUntranslated(): void
    {
        $translator = $this->translatorStub([
            'MOLLIE_VALIDATION_FIELD_INVALID' => 'The %s field is not valid. Allowed symbols are: %s.',
            'MOLLIE_VALIDATION_CLASS_LETTERS' => 'letters',
            'MOLLIE_VALIDATION_CLASS_SPACES' => 'spaces',
        ]);

        $formatter = new UserDataValidationMessageFormatter(
            $translator,
            (new ValidationRulesProvider())->createDescriber($translator),
        );

        $message = $formatter->format('firstName', 'blocked_character', '<');

        self::assertSame(
            'The firstName field is not valid. Allowed symbols are: letters, spaces, \' - ..',
            $message,
        );
    }

    /**
     * @param array<string, string> $translations
     */
    public function testFormatUsesTheTranslatedLabelAndTheSameSentenceAsTheStripeModule(): void
    {
        $translator = $this->translatorStub([
            'MOLLIE_VALIDATION_FIELD_INVALID' => 'The %1$s field is not valid. Allowed symbols are: %2$s',
            'MOLLIE_VALIDATION_LABEL_STREET' => 'street',
            'MOLLIE_VALIDATION_CLASS_LETTERS' => 'letters',
            'MOLLIE_VALIDATION_CLASS_DIGITS' => 'digits',
            'MOLLIE_VALIDATION_CLASS_SPACES' => 'spaces',
        ]);
        $formatter = new UserDataValidationMessageFormatter(
            $translator,
            (new ValidationRulesProvider())->createDescriber($translator),
        );

        self::assertSame(
            "The street field is not valid. Allowed symbols are: letters, digits, spaces, ' - . , /",
            $formatter->format('street', 'blocked_character', ':'),
        );
    }

    public function testShipsATranslationForEveryLabelTheRulesFileNeeds(): void
    {
        $en = $this->langFile('translations/en/mollie_lang.php');
        $de = $this->langFile('translations/de/mollie_lang.php');
        $adminEn = $this->langFile('views/admin_twig/en/mollie_lang.php');

        foreach (array_keys((new ValidationRulesProvider())->getFieldAllowMap()) as $field) {
            $key = 'MOLLIE_VALIDATION_LABEL_' . strtoupper($field);
            $isAdminField = in_array($field, ['captureReason', 'refundDescription'], true);
            $target = $isAdminField ? $adminEn : $en;
            self::assertArrayHasKey($key, $target, "missing label for $field");
            if (!$isAdminField) {
                self::assertArrayHasKey($key, $de, "missing German label for $field");
            }
        }
        foreach (['MOLLIE_VALIDATION_FIELD_INVALID', 'MOLLIE_VALIDATION_REVIEW_ADDRESS', 'MOLLIE_VALIDATION_INVALID_USER_DATA', 'MOLLIE_CHECKOUT_UNAVAILABLE'] as $key) {
            self::assertArrayHasKey($key, $en);
            self::assertArrayHasKey($key, $de);
        }
    }

    /**
     * @return array<string, string>
     */
    private function langFile(string $relative): array
    {
        $aLang = [];
        require dirname(__DIR__, 3) . '/' . $relative;

        return $aLang;
    }

    private function translatorStub(array $translations): LanguageTranslatorInterface
    {
        return new class ($translations) implements LanguageTranslatorInterface {
            /** @param array<string, string> $translations */
            public function __construct(private readonly array $translations)
            {
            }

            public function translateString(string $key): string
            {
                return $this->translations[$key] ?? $key;
            }
        };
    }

    private function formatter(): UserDataValidationMessageFormatter
    {
        $translator = $this->translatorStub(['MOLLIE_VALIDATION_FIELD_INVALID' => '%s / %s']);

        return new UserDataValidationMessageFormatter(
            $translator,
            (new ValidationRulesProvider())->createDescriber($translator),
        );
    }
}
