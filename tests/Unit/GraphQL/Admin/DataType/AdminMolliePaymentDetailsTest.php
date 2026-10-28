<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\DataType;

use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AdminMolliePaymentDetails;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MolliePaymentDetails;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Field;

final class AdminMolliePaymentDetailsTest extends TestCase
{
    public function testExposesExactlyTheDocumentedFields(): void
    {
        $fields = [];
        foreach ((new ReflectionClass(AdminMolliePaymentDetails::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Field::class) !== []) {
                $fields[] = $method->getName();
            }
        }

        self::assertEqualsCanonicalizing(
            ['paymentId', 'dashboardUrl', 'methodLabel', 'methodDetail', 'mollieStatus', 'amountReleased', 'amountReleasable', 'multiCapture', 'apiError'],
            $fields,
        );
    }

    public function testReadsFromTheDetails(): void
    {
        $type = new AdminMolliePaymentDetails(new MolliePaymentDetails('tr_1', 'https://d', 'iDEAL', null, 'paid', 5.5, 0.0, true, null));

        self::assertSame(['tr_1', 'https://d', 'iDEAL', null, 'paid', 5.5, 0.0, true, null], [
            $type->paymentId(), $type->dashboardUrl(), $type->methodLabel(), $type->methodDetail(), $type->mollieStatus(),
            $type->amountReleased(), $type->amountReleasable(), $type->multiCapture(), $type->apiError(),
        ]);
    }

    public function testNothingInTheTypeCanCarryASecret(): void
    {
        foreach ((new ReflectionClass(AdminMolliePaymentDetails::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertDoesNotMatchRegularExpression('/key|secret|token/i', $method->getName());
        }
    }
}
