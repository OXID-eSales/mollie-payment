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
 * GRAPH-QL / MS4 — graphql-base discovers the Mollie mutations through this mapper; every type the checkout
 * mutations use (results, the `guest` input) is payment-base's, so none is mapped for them. PAY-5 — the admin API
 * is mapped only when oxid-admin-graphql and payment-base's admin payment API are installed.
 */
final class NamespaceMapperTest extends TestCase
{
    private const CHECKOUT = 'OxidEsales\\Payments\\Mollie\\GraphQL\\Controller';
    private const ADMIN_CONTROLLERS = 'OxidEsales\\Payments\\Mollie\\GraphQL\\Admin\\Controller';
    private const ADMIN_TYPES = 'OxidEsales\\Payments\\Mollie\\GraphQL\\Admin\\DataType';
    private const ADMIN_RELATIONS = 'OxidEsales\\Payments\\Mollie\\GraphQL\\Admin\\Relation';

    public function testWithoutTheAdminApiOnlyTheCheckoutControllerIsMappedAndNoType(): void
    {
        $mapper = new NamespaceMapper(false);

        $controllers = $mapper->getControllerNamespaceMapping();
        self::assertSame([self::CHECKOUT], array_keys($controllers));
        self::assertDirectoryExists($controllers[self::CHECKOUT]);
        self::assertSame([], $mapper->getTypeNamespaceMapping(), 'the input types live in payment-base, once');
    }

    public function testWithTheAdminApiItsControllerTypesAndRelationAreMappedToExistingDirectories(): void
    {
        $mapper = new NamespaceMapper(true);

        $controllers = $mapper->getControllerNamespaceMapping();
        self::assertArrayHasKey(self::CHECKOUT, $controllers);
        self::assertArrayHasKey(self::ADMIN_CONTROLLERS, $controllers);
        $types = $mapper->getTypeNamespaceMapping();
        self::assertSame([self::ADMIN_TYPES, self::ADMIN_RELATIONS], array_keys($types));
        foreach ([...$controllers, ...$types] as $directory) {
            self::assertDirectoryExists($directory);
        }
    }

    public function testTheAdminApiIsDetectedFromTheClassesWhenNotToldOtherwise(): void
    {
        // The unit bootstrap stubs AdminOrder; payment-base's AdminPaymentContract is real.
        self::assertArrayHasKey(self::ADMIN_CONTROLLERS, (new NamespaceMapper())->getControllerNamespaceMapping());
    }
}
