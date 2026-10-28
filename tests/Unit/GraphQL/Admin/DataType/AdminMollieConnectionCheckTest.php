<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\DataType;

use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AdminMollieConnectionCheck;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConnectionStatus;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TheCodingMachine\GraphQLite\Annotations\Field;

final class AdminMollieConnectionCheckTest extends TestCase
{
    public function testExposesTheVerdictAndTheModeAndNothingElse(): void
    {
        $fields = [];
        foreach ((new ReflectionClass(AdminMollieConnectionCheck::class))->getMethods() as $method) {
            if ($method->getAttributes(Field::class) !== []) {
                $fields[] = $method->getName();
            }
        }
        $check = new AdminMollieConnectionCheck(new MollieConnectionStatus('invalid_key', 'live'));

        self::assertEqualsCanonicalizing(['status', 'ok', 'mode'], $fields);
        self::assertSame('invalid_key', $check->status());
        self::assertFalse($check->ok());
        self::assertSame('live', $check->mode());
        self::assertTrue((new AdminMollieConnectionCheck(new MollieConnectionStatus('ok', 'test')))->ok());
    }
}
