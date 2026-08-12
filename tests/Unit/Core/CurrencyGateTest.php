<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Core;

use OxidEsales\Payments\Mollie\Adapter\OxidCurrencyReader;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Sprint 11 Story 11 (F16, F17) — the currency gate is closed by default, and there is exactly one
 * place that reads a currency out of OXID.
 */
#[CoversClass(MollieDefinitions::class)]
#[CoversClass(OxidCurrencyReader::class)]
#[Group('F16')]
final class CurrencyGateTest extends TestCase
{
    public function testUnknownPaymentIdIsNotSupported(): void
    {
        // Previously true: getSupportedCurrencies() returns [] for an unknown id, and [] meant "all".
        self::assertFalse(MollieDefinitions::supportsCurrency('not_a_mollie_payment', 'GBP'));
        self::assertFalse(MollieDefinitions::supportsCurrency('not_a_mollie_payment', 'EUR'));
    }

    public function testBlankCurrencyIsNotSupported(): void
    {
        self::assertFalse(MollieDefinitions::supportsCurrency(MollieDefinitions::PAYMENT_ID, ''));
    }

    public function testEuroIsStillSupportedForTheRealPaymentId(): void
    {
        self::assertTrue(MollieDefinitions::supportsCurrency(MollieDefinitions::PAYMENT_ID, 'EUR'));
        self::assertTrue(MollieDefinitions::supportsCurrency(MollieDefinitions::PAYMENT_ID, 'eur'));
    }

    public function testNonEuroIsRejectedForTheRealPaymentId(): void
    {
        self::assertFalse(MollieDefinitions::supportsCurrency(MollieDefinitions::PAYMENT_ID, 'GBP'));
    }

    // --- OxidCurrencyReader (F17) ---

    public function testCurrencyReaderReturnsTheUppercasedCode(): void
    {
        self::assertSame('EUR', OxidCurrencyReader::codeFrom((object) ['name' => 'eur']));
        self::assertSame('GBP', OxidCurrencyReader::codeFrom((object) ['name' => ' GBP ']));
    }

    public function testCurrencyReaderReportsFailureInsteadOfGuessing(): void
    {
        self::assertNull(OxidCurrencyReader::codeFrom(null));
        self::assertNull(OxidCurrencyReader::codeFrom('not an object'));
        self::assertNull(OxidCurrencyReader::codeFrom((object) []));
        self::assertNull(OxidCurrencyReader::codeFrom((object) ['name' => '']));
        self::assertNull(OxidCurrencyReader::codeFrom((object) ['name' => ['array']]));
    }

    /**
     * The four independent `?? 'EUR'` / `?: 'EUR'` call sites are what made an unverified guess look
     * like a corroborated fact. One reader, no literals elsewhere.
     */
    public function testNoEuroLiteralSurvivesOutsideTheDefinitions(): void
    {
        $offenders = [];

        foreach ($this->phpFilesInSrc() as $file) {
            if ($file->getFilename() === 'MollieDefinitions.php') {
                continue;
            }

            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (!str_contains($line, "'EUR'")) {
                    continue;
                }
                // Docblocks explaining the removed fallbacks are allowed to name it.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue;
                }
                $offenders[] = $file->getFilename() . ':' . ($number + 1);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "A hardcoded 'EUR' reappeared. Read the currency through OxidCurrencyReader and let the "
            . "caller decide what an unreadable currency means:\n  " . implode("\n  ", $offenders),
        );
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFilesInSrc(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
