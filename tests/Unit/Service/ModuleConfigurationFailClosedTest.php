<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Adapter\Exception\MollieConfigurationException;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sprint 11 Story 7 (F4) — an unreadable configuration must refuse to transact, not quietly pick
 * the other account.
 *
 * `readSetting()` returned `''` for any failure — DAO error in the constructor, missing setting,
 * anything. `getMode()` then compared that `''` against `'live'`, concluded **test**, and
 * `getApiKey()` handed back `sMollieTestKey`. In a live shop with a test key configured (i.e. any
 * shop that was ever tested) checkout kept working against Mollie's *test* account: shoppers
 * completed payment, the return leg fulfilled the order, OXPAID was stamped, and no real money was
 * ever collected. The class had no logger, so nothing was recorded.
 *
 * Defaulting to *live* would be worse. The point of this story is that it is a false dilemma: the
 * correct answer for a payment module that cannot read its own configuration is to fail closed.
 *
 * The distinction that has to survive: a config that cannot be READ is an error, while a setting
 * that is legitimately UNSET still falls back to the metadata default (`test`).
 */
#[CoversClass(ModuleConfigurationService::class)]
#[Group('F4')]
final class ModuleConfigurationFailClosedTest extends TestCase
{
    public function testUnreadableConfigurationThrowsInsteadOfClaimingTestMode(): void
    {
        $config = new UnreadableModuleConfigurationService();

        $this->expectException(MollieConfigurationException::class);
        $config->getMode();
    }

    public function testUnreadableConfigurationNeverHandsOutAnApiKey(): void
    {
        $config = new UnreadableModuleConfigurationService();

        $this->expectException(MollieConfigurationException::class);
        $config->getApiKey();
    }

    public function testUnreadableConfigurationAlsoFailsIsTestMode(): void
    {
        $config = new UnreadableModuleConfigurationService();

        $this->expectException(MollieConfigurationException::class);
        $config->isTestMode();
    }

    /**
     * The case that must NOT become an exception: `sMollieMode` simply not set. metadata.php
     * declares `test` as the default, so an absent value is a real default, not a failure.
     */
    public function testUnsetModeStillDefaultsToTest(): void
    {
        $config = new TestableModuleConfigurationService([]);

        self::assertSame(MollieDefinitions::MODE_TEST, $config->getMode());
        self::assertTrue($config->isTestMode());
    }

    public function testEmptyStringModeStillDefaultsToTest(): void
    {
        $config = new TestableModuleConfigurationService(['sMollieMode' => '']);

        self::assertSame(MollieDefinitions::MODE_TEST, $config->getMode());
    }

    public function testUnreadableConfigurationIsLoggedAtErrorLevel(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(
                self::stringContains('Mollie'),
                self::callback(static fn (array $context): bool => $context !== []),
            );

        $config = new UnreadableModuleConfigurationService($logger);

        try {
            $config->getMode();
        } catch (MollieConfigurationException) {
            // expected — we are asserting the log, not the throw
        }
    }

    public function testReadableConfigurationLogsNothing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $config = new TestableModuleConfigurationService(
            ['sMollieMode' => MollieDefinitions::MODE_LIVE, 'sMollieLiveKey' => 'live_' . str_repeat('b', 30)],
            $logger,
        );

        self::assertSame(MollieDefinitions::MODE_LIVE, $config->getMode());
    }
}
