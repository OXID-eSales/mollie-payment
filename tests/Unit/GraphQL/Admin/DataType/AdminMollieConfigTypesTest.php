<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\DataType;

use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AdminMollieConfig;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\ConfigFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Field;

final class AdminMollieConfigTypesTest extends TestCase
{
    public function testConfig_ExposesExactlyTheDocumentedFields(): void
    {
        $fields = $this->fieldNamesOf(AdminMollieConfig::class);

        self::assertEqualsCanonicalizing([
            'mode', 'captureMode', 'multiCapture', 'profileId', 'webhookUrlOverride', 'effectiveWebhookUrl', 'logLevel',
            'testKeySet', 'liveKeySet', 'version',
        ], $fields);
        foreach ($fields as $field) {
            self::assertDoesNotMatchRegularExpression('/key$/i', $field, 'no field may carry an API key value');
        }
    }

    public function testConfig_ReadsFromTheSnapshot(): void
    {
        $config = new AdminMollieConfig(ConfigFixtures::snapshot(['liveKeySet' => true, 'multiCapture' => true]));

        self::assertTrue($config->isLiveKeySet());
        self::assertTrue($config->isMultiCapture());
        self::assertSame('pfl_AbC123', $config->getProfileId());
        self::assertSame('https://shop.test/index.php?cl=MollieWebhookController', $config->getEffectiveWebhookUrl());
        self::assertSame(ConfigFixtures::snapshot(['liveKeySet' => true, 'multiCapture' => true])->version(), $config->getVersion());
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function fieldNamesOf(string $class): array
    {
        $names = [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Field::class) !== []) {
                $names[] = lcfirst((string) preg_replace('/^(get|is|has)(?=[A-Z])/', '', $method->getName()));
            }
        }

        return $names;
    }
}
