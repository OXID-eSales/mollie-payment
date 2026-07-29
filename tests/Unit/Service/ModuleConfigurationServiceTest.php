<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleConfigurationService::class)]
final class ModuleConfigurationServiceTest extends TestCase
{
    public function testGetApiKey_ReturnsTestKeyInTestMode(): void
    {
        $config = new TestableModuleConfigurationService([
            'sMollieMode' => MollieDefinitions::MODE_TEST,
            'sMollieTestKey' => 'test_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'sMollieLiveKey' => 'live_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ]);

        self::assertTrue($config->isTestMode());
        self::assertSame('test_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $config->getApiKey());
    }

    public function testGetApiKey_ReturnsLiveKeyInLiveMode(): void
    {
        $config = new TestableModuleConfigurationService([
            'sMollieMode' => MollieDefinitions::MODE_LIVE,
            'sMollieTestKey' => 'test_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'sMollieLiveKey' => 'live_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ]);

        self::assertFalse($config->isTestMode());
        self::assertSame(MollieDefinitions::MODE_LIVE, $config->getMode());
        self::assertSame('live_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $config->getApiKey());
    }

    public function testGetProfileId_ReturnsTrimmedConfiguredValue(): void
    {
        $config = new TestableModuleConfigurationService(['sMollieProfileId' => '  pfl_cVf8bSWdQV  ']);
        self::assertSame('pfl_cVf8bSWdQV', $config->getProfileId());
    }

    public function testGetProfileId_EmptyWhenUnset(): void
    {
        $config = new TestableModuleConfigurationService([]);
        self::assertSame('', $config->getProfileId());
    }

    public function testGetCaptureMode_DefaultsToAutomatic(): void
    {
        $config = new TestableModuleConfigurationService([]);
        self::assertSame(MollieDefinitions::CAPTURE_MODE_AUTOMATIC, $config->getCaptureMode());
        self::assertFalse($config->isManualCapture());
    }

    public function testGetCaptureMode_Manual(): void
    {
        $config = new TestableModuleConfigurationService([
            'sMollieCaptureMode' => MollieDefinitions::CAPTURE_MODE_MANUAL,
        ]);
        self::assertTrue($config->isManualCapture());
    }

    public function testGetLogLevel_DefaultsToErrorsForUnknownValue(): void
    {
        $config = new TestableModuleConfigurationService(['sMollieLogLevel' => 'bogus']);
        self::assertSame(MollieDefinitions::LOG_LEVEL_ERRORS, $config->getLogLevel());
    }

    public function testGetLogLevel_HonoursConfiguredValue(): void
    {
        $config = new TestableModuleConfigurationService(['sMollieLogLevel' => MollieDefinitions::LOG_LEVEL_DEBUG]);
        self::assertSame(MollieDefinitions::LOG_LEVEL_DEBUG, $config->getLogLevel());
    }

    public function testIsWebhookLoggingEnabled_FalseWhenLevelIsOff(): void
    {
        $config = new TestableModuleConfigurationService(['sMollieLogLevel' => MollieDefinitions::LOG_LEVEL_OFF]);
        self::assertFalse($config->isWebhookLoggingEnabled());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonOffLogLevels(): iterable
    {
        yield 'errors' => [MollieDefinitions::LOG_LEVEL_ERRORS];
        yield 'normal' => [MollieDefinitions::LOG_LEVEL_NORMAL];
        yield 'debug' => [MollieDefinitions::LOG_LEVEL_DEBUG];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonOffLogLevels')]
    public function testIsWebhookLoggingEnabled_TrueForAnyNonOffLevel(string $level): void
    {
        $config = new TestableModuleConfigurationService(['sMollieLogLevel' => $level]);
        self::assertTrue($config->isWebhookLoggingEnabled());
    }

    public function testIsFrontendDebugEnabled_TrueOnlyForDebugLevel(): void
    {
        $debug = new TestableModuleConfigurationService(['sMollieLogLevel' => MollieDefinitions::LOG_LEVEL_DEBUG]);
        $normal = new TestableModuleConfigurationService(['sMollieLogLevel' => MollieDefinitions::LOG_LEVEL_NORMAL]);
        $errors = new TestableModuleConfigurationService(['sMollieLogLevel' => MollieDefinitions::LOG_LEVEL_ERRORS]);
        $off = new TestableModuleConfigurationService(['sMollieLogLevel' => MollieDefinitions::LOG_LEVEL_OFF]);

        self::assertTrue($debug->isFrontendDebugEnabled());
        self::assertFalse($normal->isFrontendDebugEnabled());
        self::assertFalse($errors->isFrontendDebugEnabled());
        self::assertFalse($off->isFrontendDebugEnabled());
    }
}
