<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\AllowedSymbolsDescriber;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AllowedSymbolsDescriber::class)]
final class AllowedSymbolsDescriberTest extends TestCase
{
    public function testTranslatesClassTokensAndPassesLiteralsThrough(): void
    {
        $describer = new AllowedSymbolsDescriber($this->translator(), [
            'street' => "UNICODE_LETTERS NUMBERS SPACES ' - . , /",
        ]);

        self::assertSame("letters, digits, spaces, ' - . , /", $describer->describe('street'));
    }

    public function testDeduplicatesWordsWhenTwoTokensMapToTheSameWord(): void
    {
        $describer = new AllowedSymbolsDescriber($this->translator(), ['x' => 'UNICODE_LETTERS LETTERS']);

        self::assertSame('letters', $describer->describe('x'));
    }

    public function testDescribesAnUnknownFieldAsNothing(): void
    {
        $describer = new AllowedSymbolsDescriber($this->translator(), ['street' => 'LETTERS']);

        self::assertSame('', $describer->describe('unknown'));
    }

    public function testIgnoresRepeatedSeparators(): void
    {
        $describer = new AllowedSymbolsDescriber($this->translator(), ['x' => 'NUMBERS  -   +']);

        self::assertSame('digits, - +', $describer->describe('x'));
    }

    private function translator(): LanguageTranslatorInterface
    {
        return new class implements LanguageTranslatorInterface {
            public function translateString(string $key): string
            {
                return match ($key) {
                    'MOLLIE_VALIDATION_CLASS_LETTERS' => 'letters',
                    'MOLLIE_VALIDATION_CLASS_DIGITS' => 'digits',
                    'MOLLIE_VALIDATION_CLASS_SPACES' => 'spaces',
                    default => $key,
                };
            }
        };
    }
}
