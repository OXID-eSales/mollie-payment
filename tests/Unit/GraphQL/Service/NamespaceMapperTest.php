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
 * mapper; the result types are payment-base's; the guest input factories are ours.
 */
final class NamespaceMapperTest extends TestCase
{
    public function testMapsTheControllerNamespaceToAnExistingDirectoryAndTheGuestInputTypes(): void
    {
        $mapper = new NamespaceMapper();

        $controllers = $mapper->getControllerNamespaceMapping();
        self::assertArrayHasKey('OxidEsales\\Payments\\Mollie\\GraphQL\\Controller', $controllers);
        self::assertDirectoryExists($controllers['OxidEsales\\Payments\\Mollie\\GraphQL\\Controller']);
        $types = $mapper->getTypeNamespaceMapping();
        self::assertArrayHasKey('OxidEsales\\Payments\\Mollie\\GraphQL\\Input', $types);
        self::assertDirectoryExists($types['OxidEsales\\Payments\\Mollie\\GraphQL\\Input']);
    }
}
