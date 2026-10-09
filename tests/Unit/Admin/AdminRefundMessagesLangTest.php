<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MOL-30: the two messages a failed admin refund can show exist in both admin languages.
 */
final class AdminRefundMessagesLangTest extends TestCase
{
    #[DataProvider('languages')]
    public function testTheRefundFailureMessagesAreTranslated(string $language): void
    {
        $aLang = [];
        require dirname(__DIR__, 3) . "/views/admin_twig/{$language}/mollie_lang.php";

        foreach (['MOLLIE_ADMIN_REFUND_CAPTURE_SETTLING', 'MOLLIE_ADMIN_REFUND_FAILED'] as $ident) {
            self::assertArrayHasKey($ident, $aLang, "$ident missing in $language");
            self::assertNotSame('', trim((string) $aLang[$ident]));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function languages(): iterable
    {
        yield 'en' => ['en'];
        yield 'de' => ['de'];
    }
}
