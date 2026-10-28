<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Service;

/**
 * Tells graphql-base where the Mollie checkout mutations live and (when oxid-admin-graphql is installed) the Mollie
 * admin API: settings operations, connection check, release, their types and the `mollie` extension of the Payment
 * tab's contract. The result types (`Checkout*Result`) and the `guest` input (`GuestCheckoutInput`) are
 * payment-base's, mapped by its own namespace mapper: the storefront side defines no GraphQL type.
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
    // The admin API is built on oxid-admin-graphql's types and payment-base's admin payment API: both must exist.
    private const ADMIN_ORDER_TYPE = 'OxidEsales\\GraphQL\\Admin\\Order\\DataType\\AdminOrder';
    private const ADMIN_PAYMENT_CONTRACT_TYPE =
        'OxidEsales\\PaymentBase\\GraphQL\\Admin\\DataType\\AdminPaymentContract';

    /**
     * @param bool|null $adminApiAvailable null: ask the autoloader whether oxid-admin-graphql and payment-base's
     *                                      admin payment API are there
     */
    public function __construct(private readonly ?bool $adminApiAvailable = null)
    {
    }

    /**
     * @return array<string, string>
     */
    public function getControllerNamespaceMapping(): array
    {
        $mapping = [
            'OxidEsales\\Payments\\Mollie\\GraphQL\\Controller' => __DIR__ . '/../Controller/',
        ];
        if ($this->adminApiInstalled()) {
            $mapping['OxidEsales\\Payments\\Mollie\\GraphQL\\Admin\\Controller'] = __DIR__ . '/../Admin/Controller/';
        }

        return $mapping;
    }

    /**
     * @return array<string, string>
     */
    public function getTypeNamespaceMapping(): array
    {
        if (!$this->adminApiInstalled()) {
            return [];
        }

        return [
            'OxidEsales\\Payments\\Mollie\\GraphQL\\Admin\\DataType' => __DIR__ . '/../Admin/DataType/',
            'OxidEsales\\Payments\\Mollie\\GraphQL\\Admin\\Relation' => __DIR__ . '/../Admin/Relation/',
        ];
    }

    private function adminApiInstalled(): bool
    {
        return $this->adminApiAvailable
            ?? (class_exists(self::ADMIN_ORDER_TYPE) && class_exists(self::ADMIN_PAYMENT_CONTRACT_TYPE));
    }
}
