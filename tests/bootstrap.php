<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 *
 * Bootstrap for the Mollie module tests. Loads the OXID shop context when available
 * (for integration tests) and otherwise falls back to the module's own Composer autoload
 * so pure unit tests can run without the shop bootstrap.
 *
 * Usage (from project root, inside docker):
 *   docker compose exec php php vendor/bin/phpunit -c extensions/mollie-payment/tests/phpunit.xml --testsuite Unit
 *   docker compose exec php php vendor/bin/phpunit -c extensions/mollie-payment/tests/phpunit.xml --testsuite Integration
 */

declare(strict_types=1);

$possibleBootstraps = [
    '/var/www/source/bootstrap.php',
    dirname(__DIR__, 4) . '/source/bootstrap.php',
    dirname(__DIR__, 3) . '/bootstrap.php',
];

$bootstrapped = false;
foreach ($possibleBootstraps as $path) {
    if (file_exists($path)) {
        require_once $path;
        $bootstrapped = true;
        break;
    }
}

if (!$bootstrapped) {
    $moduleAutoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (file_exists($moduleAutoload)) {
        require_once $moduleAutoload;
        $bootstrapped = true;
    } else {
        $shopAutoload = dirname(__DIR__, 4) . '/source/vendor/autoload.php';
        if (file_exists($shopAutoload)) {
            require_once $shopAutoload;
            $bootstrapped = true;
        }
    }
}

if (!$bootstrapped) {
    throw new RuntimeException(
        'Neither OXID shop bootstrap nor a Composer autoload could be located. '
        . 'Run "composer install" in the module (for standalone unit tests) '
        . 'or execute the tests from inside the OXID SDK docker environment.'
    );
}

// Register the module's own PSR-4 namespace so tests can run before the module
// is registered in the shop's composer.json. Once the module ships via Composer
// this block becomes a no-op (the shop autoloader already knows the namespace).
spl_autoload_register(static function (string $class): void {
    $prefix = 'OxidEsales\\Payments\\Mollie\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $isTest = str_starts_with($relative, 'Tests\\');
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
    $baseDir = $isTest
        ? dirname(__DIR__) . '/tests/'
        : dirname(__DIR__) . '/src/Mollie/';
    $file = $baseDir . ($isTest ? substr($relativePath, strlen('Tests' . DIRECTORY_SEPARATOR)) : $relativePath) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
