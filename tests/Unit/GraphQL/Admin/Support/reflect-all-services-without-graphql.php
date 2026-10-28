<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 *
 * Runs in a fresh PHP process in which NO GraphQL package can be loaded (graphql-base, graphql-storefront,
 * oxid-admin-graphql, GraphQLite, webonyx): an autoloader in front of everything refuses their namespaces, whatever
 * is installed. It reflects the class of EVERY service of services.yaml the way the container does at module
 * activation (ancestry, attributes, parameter and return types) and checks that no service references a GraphQL
 * service without `@?`. Prints "OK <count>" on success, or the first failure.
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

const BLOCKED = ['OxidEsales\\GraphQL\\', 'TheCodingMachine\\GraphQLite\\', 'GraphQL\\'];

spl_autoload_register(static function (string $class): void {
    foreach (BLOCKED as $prefix) {
        if (str_starts_with($class, $prefix)) {
            throw new RuntimeException('GraphQL class needed without GraphQL: ' . $class);
        }
    }
}, true, true);

$moduleRoot = dirname(__DIR__, 5);
require $moduleRoot . '/vendor/autoload.php';

if (!interface_exists(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class)) {
    eval('namespace Symfony\\Component\\EventDispatcher; interface EventSubscriberInterface { public static function getSubscribedEvents(): array; }');
}

$services = Yaml::parseFile($moduleRoot . '/services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];

$hard = [];
array_walk_recursive($services, static function (mixed $value) use (&$hard): void {
    if (is_string($value) && preg_match('/^@(?!\?)(OxidEsales\\\\GraphQL\\\\|TheCodingMachine\\\\)/', $value) === 1) {
        $hard[] = $value;
    }
});
if ($hard !== []) {
    echo 'HARD REFERENCE: ' . implode(', ', $hard) . "\n";
    exit(1);
}

$reflected = 0;
foreach ($services as $id => $definition) {
    if (str_starts_with((string) $id, '_') || (is_array($definition) && isset($definition['alias']))) {
        continue;
    }
    $class = is_array($definition) && isset($definition['class']) ? $definition['class'] : $id;
    if (!str_contains((string) $class, '\\') || str_ends_with((string) $class, '\\')) {
        continue;
    }
    try {
        $reflection = new ReflectionClass($class);
        $reflection->getInterfaceNames();
        for ($parent = $reflection->getParentClass(); $parent; $parent = $parent->getParentClass()) {
            $parent->getName();
        }
        foreach ($reflection->getAttributes() as $attribute) {
            $attribute->getName();
        }
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $parameter->getType()?->__toString();
            }
            $method->getReturnType()?->__toString();
            foreach ($method->getAttributes() as $attribute) {
                $attribute->getName();
            }
        }
    } catch (Throwable $failure) {
        echo "FAILED $class: " . $failure->getMessage() . "\n";
        exit(1);
    }
    ++$reflected;
}

echo "OK $reflected\n";
