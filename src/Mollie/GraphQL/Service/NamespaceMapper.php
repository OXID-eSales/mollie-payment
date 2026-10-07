<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Service;

/**
 * Tells graphql-base where the Mollie checkout mutations live. The result
 * types are payment-base's (`Checkout*Result`), so no type namespace here.
 *
 * Mirrors `OxidEsales\GraphQL\Base\Framework\NamespaceMapperInterface`
 * WITHOUT implementing it, on purpose: graphql-base is optional for this
 * module, and module activation compiles the container, whose compiler
 * passes reflect (load) the class of every service. A class implementing an
 * interface from an absent package is a fatal "Interface not found" and the
 * module cannot be activated on a shop without GraphQL (payment-base's CI
 * died on exactly that). graphql-base only iterates the tagged services and
 * calls the two methods. OptionalGraphQlDependencyTest pins the parity.
 *
 * @since 3.4.0
 */
final class NamespaceMapper
{
    /**
     * @return array<string, string>
     */
    public function getControllerNamespaceMapping(): array
    {
        return [
            'OxidEsales\\Payments\\Mollie\\GraphQL\\Controller' => __DIR__ . '/../Controller/',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getTypeNamespaceMapping(): array
    {
        return [];
    }
}
