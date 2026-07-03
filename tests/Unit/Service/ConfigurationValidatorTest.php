<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\ConfigurationValidator;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationValidator::class)]
final class ConfigurationValidatorTest extends TestCase
{
    public function testValidate_TestModeWithLiveKey_Fails(): void
    {
        $result = $this->validatorFor(MollieDefinitions::MODE_TEST, 'live_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')->validate();
        self::assertFalse($result->isValid());
        self::assertNotEmpty($result->getErrors());
    }

    public function testValidate_KeyWrongPrefix_Fails(): void
    {
        $result = $this->validatorFor(MollieDefinitions::MODE_TEST, 'xyz_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')->validate();
        self::assertFalse($result->isValid());
    }

    public function testValidate_EmptyKey_Fails(): void
    {
        $result = $this->validatorFor(MollieDefinitions::MODE_TEST, '')->validate();
        self::assertFalse($result->isValid());
    }

    public function testValidate_TooShortKey_Fails(): void
    {
        $result = $this->validatorFor(MollieDefinitions::MODE_TEST, 'test_short')->validate();
        self::assertFalse($result->isValid());
    }

    public function testValidate_WellFormed_Passes(): void
    {
        $result = $this->validatorFor(MollieDefinitions::MODE_TEST, 'test_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->validate();
        self::assertTrue($result->isValid());
        self::assertSame([], $result->getErrors());
    }

    private function validatorFor(string $mode, string $key): ConfigurationValidator
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getMode')->willReturn($mode);
        $config->method('getApiKey')->willReturn($key);

        return new ConfigurationValidator($config);
    }
}
