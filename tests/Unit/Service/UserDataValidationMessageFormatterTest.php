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

        $formatter = new UserDataValidationMessageFormatter($translator, new ValidationRulesProvider());

        $message = $formatter->format('firstName', 'blocked_character', '<');

        self::assertSame(
            'The firstName field is not valid. Allowed symbols are: letters, spaces, \' - ..',
            $message,
        );
    }

    /**
     * @param array<string, string> $translations
     */
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

        return new UserDataValidationMessageFormatter($translator, new ValidationRulesProvider());
    }
}
