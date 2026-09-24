<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\ValidationRulesProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationRulesProvider::class)]
final class ValidationRulesProviderTest extends TestCase
{
    // MOL-15: the same logical field set the Stripe module declares, plus Mollie's `email`
    // (Mollie receives the shopper's e-mail) and the two admin free-text fields.
    private const EXPECTED_FIELDS = [
        'firstName', 'lastName', 'street', 'houseNumber', 'postalCode', 'city',
        'company', 'vatId', 'additionalInfo', 'phone', 'cellPhone', 'personalPhone', 'fax',
        'email', 'captureReason', 'refundDescription',
    ];

    public function testProvidesRulesForAllCollectedUserFields(): void
    {
        $map = (new ValidationRulesProvider())->getFieldAllowMap();

        foreach (self::EXPECTED_FIELDS as $field) {
            self::assertArrayHasKey($field, $map, "Missing validation rule for field '$field'");
            self::assertNotSame('', $map[$field], "Empty allow rule for field '$field'");
        }
    }

    public function testEmailAllowMapIncludesAtSymbol(): void
    {
        $map = (new ValidationRulesProvider())->getFieldAllowMap();

        self::assertStringContainsString('@', $map['email']);
    }
}
