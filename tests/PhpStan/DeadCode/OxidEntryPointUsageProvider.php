<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\PhpStan\DeadCode;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Entry points of the dead-code gate (sprint 17 DC-5) that are called by a framework, not by PHP code.
 *
 * Callers invisible to a static call graph:
 * - GraphQLite builds the schema from attributes (`#[Field]`, `#[Query]`, `#[Mutation]`, ...);
 * - the shop calls the `events` of `metadata.php` by name;
 * - the shop's controller / view-config extend chain, the widget and the webhook controller are called by
 *   templates and by `cl=` / `fnc=` request parameters;
 * - the container builds every class that services.yaml registers (its constructor);
 * - payment-base calls a method through the interface or base class it declares (`handle()`, `getPriority()`, ...).
 *   payment-base is symlinked into vendor/ here and in CI, so the detector does not recognise it as a vendor package.
 */
final class OxidEntryPointUsageProvider extends ReflectionBasedMemberUsageProvider
{
    private const GRAPHQLITE_ATTRIBUTE_NAMESPACE = 'TheCodingMachine\\GraphQLite\\Annotations\\';

    /** `events` of metadata.php: class => handler methods. */
    private const METADATA_EVENT_HANDLERS = [
        'OxidEsales\\Payments\\Mollie\\Core\\Events' => ['onActivate', 'onDeactivate'],
    ];

    /** Namespaces whose public methods the shop calls by name (extend chain, controllers, widget, view config). */
    private const SHOP_CALLED_CLASS_MARKERS = ['\\Controller\\', '\\Component\\Widget\\', '\\Core\\ViewConfig'];
    private const GRAPHQL_CONTROLLER_MARKER = '\\GraphQL\\';
    /**
     * Read by the Twig templates of views/twig/: class => members. The unit test checks that every name still occurs
     * in a template, so this list cannot go stale unnoticed.
     */
    public const TWIG_READ_MEMBERS = [
        'OxidEsales\\Payments\\Mollie\\Admin\\MollieContractStateHelp' => ['introIdent', 'columnIdent'],
        'OxidEsales\\Payments\\Mollie\\Service\\Result\\TransactionRow' => ['id', 'currency', 'status', 'createdAt', 'badgeTone'],
        'OxidEsales\\Payments\\Mollie\\Core\\MollieDefinitions' => ['SECRET_MODULE_SETTINGS'],
    ];

    private const PAYMENT_BASE_NAMESPACE = 'OxidEsales\\PaymentBase\\';

    /** @var array<string, true>|null constructor-declaring classes of the registered services */
    private ?array $containerBuiltConstructors = null;

    /** @var array<string, list<string>>|null */
    private ?array $containerFactories = null;

    public function __construct(private readonly string $servicesFile)
    {
    }

    public function isEntryPoint(ReflectionMethod $method): bool
    {
        if (!$method->isPublic()) {
            return false;
        }

        return $this->hasGraphQliteAttribute($method)
            || $this->isMetadataEventHandler($method)
            || $this->isCalledByTemplatesOrRequests($method)
            || $this->isBuiltByTheContainer($method)
            || $this->isContainerFactory($method)
            || $this->isReadByTwig($method->getDeclaringClass()->getName(), $method->getName())
            || $this->implementsPaymentBaseContract($method);
    }

    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        if (!$this->isEntryPoint($method)) {
            return null;
        }

        return VirtualUsageData::withNote('Called by GraphQLite, metadata.php events, a template or cl=/fnc=');
    }

    protected function shouldMarkPropertyAsRead(ReflectionProperty $property): ?VirtualUsageData
    {
        if (!$this->isReadByTwig($property->getDeclaringClass()->getName(), $property->getName())) {
            return null;
        }

        return VirtualUsageData::withNote('Read by a Twig template');
    }

    protected function shouldMarkConstantAsUsed(ReflectionClassConstant $constant): ?VirtualUsageData
    {
        if (!$this->isReadByTwig($constant->getDeclaringClass()->getName(), $constant->getName())) {
            return null;
        }

        return VirtualUsageData::withNote('Read by a Twig template');
    }

    private function hasGraphQliteAttribute(ReflectionMethod $method): bool
    {
        foreach ($method->getAttributes() as $attribute) {
            if (str_starts_with($attribute->getName(), self::GRAPHQLITE_ATTRIBUTE_NAMESPACE)) {
                return true;
            }
        }

        return false;
    }

    private function isMetadataEventHandler(ReflectionMethod $method): bool
    {
        $handlers = self::METADATA_EVENT_HANDLERS[$method->getDeclaringClass()->getName()] ?? [];

        return in_array($method->getName(), $handlers, true);
    }

    private function isCalledByTemplatesOrRequests(ReflectionMethod $method): bool
    {
        $className = $method->getDeclaringClass()->getName();
        if (str_contains($className, self::GRAPHQL_CONTROLLER_MARKER)) {
            return false;
        }
        foreach (self::SHOP_CALLED_CLASS_MARKERS as $marker) {
            if (str_contains($className, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isReadByTwig(string $className, string $member): bool
    {
        return in_array($member, self::TWIG_READ_MEMBERS[$className] ?? [], true);
    }

    private function isContainerFactory(ReflectionMethod $method): bool
    {
        $this->containerFactories ??= (new ServiceClassCollector($this->servicesFile))->factoryMethods();

        return in_array($method->getName(), $this->containerFactories[$method->getDeclaringClass()->getName()] ?? [], true);
    }

    private function isBuiltByTheContainer(ReflectionMethod $method): bool
    {
        if ($method->getName() !== '__construct') {
            return false;
        }

        return isset($this->constructorsOfRegisteredServices()[$method->getDeclaringClass()->getName()]);
    }

    /**
     * @return array<string, true>
     */
    private function constructorsOfRegisteredServices(): array
    {
        if ($this->containerBuiltConstructors !== null) {
            return $this->containerBuiltConstructors;
        }
        $declaring = [];
        foreach (array_keys((new ServiceClassCollector($this->servicesFile))->collect()) as $class) {
            $constructor = (new ReflectionClass($class))->getConstructor();
            if ($constructor !== null) {
                $declaring[$constructor->getDeclaringClass()->getName()] = true;
            }
        }
        $this->containerBuiltConstructors = $declaring;

        return $declaring;
    }

    private function implementsPaymentBaseContract(ReflectionMethod $method): bool
    {
        try {
            $prototype = $method->getPrototype();
        } catch (ReflectionException) {
            return false;
        }

        return str_starts_with($prototype->getDeclaringClass()->getName(), self::PAYMENT_BASE_NAMESPACE);
    }
}
