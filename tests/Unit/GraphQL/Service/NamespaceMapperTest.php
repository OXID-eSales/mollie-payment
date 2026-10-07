<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Service;

use OxidEsales\Payments\Mollie\GraphQL\Service\NamespaceMapper;
use PHPUnit\Framework\TestCase;

/**
 * GRAPH-QL / MS4 — graphql-base discovers the Mollie mutations through this
 * mapper; the result types are payment-base's, so no type namespace here.
 */
final class NamespaceMapperTest extends TestCase
{
    public function testMapsTheControllerNamespaceToAnExistingDirectoryAndNoTypes(): void
    {
        $mapper = new NamespaceMapper();

        $controllers = $mapper->getControllerNamespaceMapping();
        self::assertArrayHasKey('OxidEsales\\Payments\\Mollie\\GraphQL\\Controller', $controllers);
        self::assertDirectoryExists($controllers['OxidEsales\\Payments\\Mollie\\GraphQL\\Controller']);
        self::assertSame([], $mapper->getTypeNamespaceMapping());
    }
}
