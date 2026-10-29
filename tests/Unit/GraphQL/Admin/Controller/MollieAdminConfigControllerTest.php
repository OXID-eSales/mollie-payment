<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Controller;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Controller\MollieAdminConfigController;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConnectionCheckerInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConnectionStatus;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\ConfigFixtures;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\InMemoryMollieSettings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

final class MollieAdminConfigControllerTest extends TestCase
{
    public function testEveryOperationIsAQueryNeedingTheConfigRightAndNoneIsLogged(): void
    {
        foreach (['adminMollieConfig', 'adminMollieConnectionCheck'] as $method) {
            $reflection = new ReflectionMethod(MollieAdminConfigController::class, $method);

            $rights = $reflection->getAttributes(Right::class);
            self::assertCount(1, $rights, $method);
            self::assertSame('ADMIN_PAYMENT_CONFIG', $rights[0]->getArguments()[0] ?? $rights[0]->getArguments()['name'] ?? null, $method);
            self::assertCount(1, $reflection->getAttributes(Query::class), $method);
            self::assertSame([], $reflection->getAttributes('TheCodingMachine\\GraphQLite\\Annotations\\Logged'), 'admin operations carry #[Right] only');
        }
    }

    public function testTheControllerHasNoMutation_TheSettingsChangeInTheClassicAdminOnly(): void
    {
        foreach ((new ReflectionClass(MollieAdminConfigController::class))->getMethods() as $method) {
            self::assertSame([], $method->getAttributes(Mutation::class), $method->getName());
        }
    }

    public function testAdminMollieConfig_ReturnsTheSettingsWithoutAnyKeyValue(): void
    {
        $config = $this->controller()->adminMollieConfig();

        self::assertSame('test', $config->getMode());
        self::assertTrue($config->isTestKeySet());
        self::assertFalse($config->isLiveKeySet());
        self::assertStringNotContainsString(ConfigFixtures::TEST_KEY, (string) json_encode(self::exposed($config)));
    }

    public function testAdminMollieConnectionCheck_ReportsTheVerdict(): void
    {
        $checker = new class implements MollieConnectionCheckerInterface {
            public function check(): MollieConnectionStatus
            {
                return new MollieConnectionStatus(MollieConnectionStatus::UNREACHABLE, 'live');
            }
        };

        $check = $this->controller($checker)->adminMollieConnectionCheck();

        self::assertSame('unreachable', $check->status());
        self::assertFalse($check->ok());
        self::assertSame('live', $check->mode());
    }

    private function controller(?MollieConnectionCheckerInterface $checker = null): MollieAdminConfigController
    {
        return new MollieAdminConfigController(
            new InMemoryMollieSettings(ConfigFixtures::snapshot()),
            $checker ?? $this->createStub(MollieConnectionCheckerInterface::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function exposed(object $type): array
    {
        $values = [];
        foreach ((new ReflectionClass($type))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfRequiredParameters() === 0 && !$method->isConstructor() && $method->getName() !== 'getVersion') {
                $values[$method->getName()] = $method->invoke($type);
            }
        }

        return $values;
    }
}
