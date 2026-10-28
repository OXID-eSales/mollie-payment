<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Support;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Utils\SchemaPrinter;
use Kcs\ClassFinder\Finder\Psr4Finder;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\GraphQL\Admin\Shared\Service\NamespaceMapper as AdminNamespaceMapper;
use OxidEsales\GraphQL\Base\Framework\AggregatedFinder;
use OxidEsales\GraphQL\Base\Service\NamespaceMapper as BaseNamespaceMapper;
use OxidEsales\PaymentBase\GraphQL\Service\NamespaceMapper as PaymentBaseNamespaceMapper;
use OxidEsales\Payments\Mollie\GraphQL\Service\NamespaceMapper as MollieNamespaceMapper;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Psr16Cache;
use TheCodingMachine\GraphQLite\Security\AuthenticationServiceInterface;
use TheCodingMachine\GraphQLite\Security\AuthorizationServiceInterface;
use TheCodingMachine\GraphQLite\SchemaFactory;

/**
 * Builds the GraphQL schema the way graphql-base does (namespace mappers of base, admin API, payment-base and
 * Mollie; the shop's container for the controllers), with every right granted, so the schema and the real
 * resolvers can be looked at without a token. Authorization itself is proven by the e2e suite.
 */
final class AdminSchemaProbe
{
    public static function sdl(): string
    {
        return SchemaPrinter::doPrint(self::schema());
    }

    /**
     * @return array<string, mixed>
     */
    public static function execute(string $query): array
    {
        return GraphQL::executeQuery(self::schema(), $query)->toArray();
    }

    private static function schema(): Schema
    {
        $finder = new AggregatedFinder();
        $factory = new SchemaFactory(self::noCache(), ContainerFactory::getInstance()->getContainer());
        $mappers = [
            new BaseNamespaceMapper(),
            new AdminNamespaceMapper(),
            new PaymentBaseNamespaceMapper(storefrontInstalled: false),
            new MollieNamespaceMapper(),
        ];
        foreach ($mappers as $mapper) {
            foreach ($mapper->getControllerNamespaceMapping() as $namespace => $path) {
                $finder->addFinder(new Psr4Finder($namespace, $path));
                $factory->addControllerNameSpace($namespace);
            }
            foreach ($mapper->getTypeNamespaceMapping() as $namespace => $path) {
                $finder->addFinder(new Psr4Finder($namespace, $path));
                $factory->addTypeNameSpace($namespace);
            }
        }
        $factory->setFinder($finder)
            ->setAuthenticationService(new class implements AuthenticationServiceInterface {
                public function isLogged(): bool
                {
                    return true;
                }

                public function getUser(): ?object
                {
                    return null;
                }
            })
            ->setAuthorizationService(new class implements AuthorizationServiceInterface {
                public function isAllowed(string $right, $subject = null): bool
                {
                    return true;
                }
            });

        return $factory->createSchema();
    }

    private static function noCache(): CacheInterface
    {
        return new Psr16Cache(new NullAdapter());
    }
}
