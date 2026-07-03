<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service\Factory;

use FilesystemIterator;
use OxidEsales\PaymentBase\Service\FileLogger;
use OxidEsales\PaymentBase\Service\NullFileLogger;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\Factory\MollieWebhookFileLoggerFactory;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Sprint 8 Story 2 — level-gated file logger factory.
 *
 * `off` must produce a NullFileLogger that writes nothing to disk; any other level
 * (errors/normal/debug) must produce a real FileLogger. Mirrors Stripe's
 * FileLoggerFactoryGatingTest (testable-subclass overriding getShopDirectory() with a temp dir,
 * so the test never touches OXID's Registry).
 */
#[CoversClass(MollieWebhookFileLoggerFactory::class)]
final class LoggerGatingTest extends TestCase
{
    private string $testShopDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testShopDir = sys_get_temp_dir() . '/mollie_logger_gating_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testShopDir);
        parent::tearDown();
    }

    public function testLevelOff_FactoryReturnsNullLogger_NoFileWritten(): void
    {
        $factory = $this->makeFactory(MollieDefinitions::LOG_LEVEL_OFF);

        $logger = $factory->create();
        self::assertInstanceOf(NullFileLogger::class, $logger);

        $logger->log('should not appear');

        self::assertFileDoesNotExist($this->testShopDir . '/log/mollie');
    }

    public function testLevelDebug_ReturnsRealLogger(): void
    {
        $factory = $this->makeFactory(MollieDefinitions::LOG_LEVEL_DEBUG);

        self::assertInstanceOf(FileLogger::class, $factory->create());
    }

    public function testLevelErrors_ReturnsRealLogger(): void
    {
        $factory = $this->makeFactory(MollieDefinitions::LOG_LEVEL_ERRORS);

        self::assertInstanceOf(FileLogger::class, $factory->create());
    }

    public function testLevelNormal_ReturnsRealLogger(): void
    {
        $factory = $this->makeFactory(MollieDefinitions::LOG_LEVEL_NORMAL);

        self::assertInstanceOf(FileLogger::class, $factory->create());
    }

    private function makeFactory(string $logLevel): MollieWebhookFileLoggerFactory
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('isWebhookLoggingEnabled')->willReturn($logLevel !== MollieDefinitions::LOG_LEVEL_OFF);

        $shopDir = $this->testShopDir;

        return new class ($shopDir, $config) extends MollieWebhookFileLoggerFactory {
            public function __construct(
                private readonly string $overrideShopDir,
                ModuleConfigurationServiceInterface $config,
            ) {
                parent::__construct($config);
            }

            protected function getShopDirectory(): string
            {
                return $this->overrideShopDir;
            }
        };
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
                continue;
            }
            unlink($file->getRealPath());
        }
        rmdir($path);
    }
}
