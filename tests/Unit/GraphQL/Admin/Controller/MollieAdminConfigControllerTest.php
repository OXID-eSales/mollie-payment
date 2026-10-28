<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Controller;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ConflictError;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Controller\MollieAdminConfigController;
use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AdminMollieConfigInput;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieAdminRefusal;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigChange;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigModifierInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigResult;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConnectionCheckerInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConnectionStatus;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\ConfigFixtures;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\FakeSuperadminCheck;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\InMemoryMollieSettings;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

final class MollieAdminConfigControllerTest extends TestCase
{
    public function testEveryOperationNeedsTheConfigRightAndNoneIsLogged(): void
    {
        foreach (['adminMollieConfig' => Query::class, 'adminMollieConfigModify' => Mutation::class, 'adminMollieConnectionCheck' => Query::class] as $method => $operation) {
            $reflection = new ReflectionMethod(MollieAdminConfigController::class, $method);

            $rights = $reflection->getAttributes(Right::class);
            self::assertCount(1, $rights, $method);
            self::assertSame('ADMIN_PAYMENT_CONFIG', $rights[0]->getArguments()[0] ?? $rights[0]->getArguments()['name'] ?? null, $method);
            self::assertCount(1, $reflection->getAttributes($operation), $method);
            self::assertSame([], $reflection->getAttributes('TheCodingMachine\\GraphQLite\\Annotations\\Logged'), 'admin operations carry #[Right] only');
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

    public function testAdminMollieConfigModify_MapsTheInputAndReturnsTheNewConfig(): void
    {
        $modifier = $this->modifierRecording($changes);
        $input = new AdminMollieConfigInput();
        $input->expectedVersion = 'v1';
        $input->captureMode = 'manual';
        $input->testKey = '';

        $payload = $this->controller($modifier)->adminMollieConfigModify($input);

        self::assertSame([], $payload->errors());
        self::assertSame('manual', $payload->config()?->getCaptureMode());
        self::assertInstanceOf(MollieConfigChange::class, $changes[0]);
        self::assertSame('v1', $changes[0]->expectedVersion);
        self::assertSame(['captureMode'], $changes[0]->fieldNames());
    }

    public function testAdminMollieConfigModify_ARefusalIsAnErrorOfThePayloadNotAnException(): void
    {
        $modifier = new class implements MollieConfigModifierInterface {
            public function modify(MollieConfigChange $change): MollieConfigResult
            {
                return MollieConfigResult::refused(MollieAdminRefusal::conflict('a', 'b'));
            }
        };
        $input = new AdminMollieConfigInput();
        $input->expectedVersion = 'a';

        $payload = $this->controller($modifier)->adminMollieConfigModify($input);

        self::assertNull($payload->config());
        self::assertCount(1, $payload->errors());
        self::assertInstanceOf(ConflictError::class, $payload->errors()[0]);
    }

    public function testAdminMollieConnectionCheck_ReportsTheVerdict(): void
    {
        $checker = new class implements MollieConnectionCheckerInterface {
            public function check(): MollieConnectionStatus
            {
                return new MollieConnectionStatus(MollieConnectionStatus::UNREACHABLE, 'live');
            }
        };

        $check = $this->controller(null, $checker)->adminMollieConnectionCheck();

        self::assertSame('unreachable', $check->status());
        self::assertFalse($check->ok());
        self::assertSame('live', $check->mode());
    }

    public function testConfig_TellsWhetherTheKeysAreEditable_OnlyForASuperadmin(): void
    {
        self::assertTrue($this->controller(null, null, true)->adminMollieConfig()->isSecretsEditable());
        self::assertFalse($this->controller(null, null, false)->adminMollieConfig()->isSecretsEditable());
    }

    private function controller(?MollieConfigModifierInterface $modifier = null, ?MollieConnectionCheckerInterface $checker = null, bool $superadmin = true): MollieAdminConfigController
    {
        return new MollieAdminConfigController(
            new InMemoryMollieSettings(ConfigFixtures::snapshot()),
            $modifier ?? $this->modifierRecording($ignored),
            $checker ?? $this->createStub(MollieConnectionCheckerInterface::class),
            new FakeSuperadminCheck($superadmin),
        );
    }

    /**
     * @param list<MollieConfigChange>|null $changes
     */
    private function modifierRecording(?array &$changes): MollieConfigModifierInterface
    {
        $changes = [];

        return new class ($changes) implements MollieConfigModifierInterface {
            /** @param list<MollieConfigChange> $changes */
            public function __construct(private array &$changes)
            {
            }

            public function modify(MollieConfigChange $change): MollieConfigResult
            {
                $this->changes[] = $change;

                return MollieConfigResult::done(ConfigFixtures::snapshot(['captureMode' => $change->captureMode ?? 'automatic']));
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function exposed(object $type): array
    {
        $values = [];
        foreach ((new \ReflectionClass($type))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfRequiredParameters() === 0 && !$method->isConstructor() && $method->getName() !== 'getVersion') {
                $values[$method->getName()] = $method->invoke($type);
            }
        }

        return $values;
    }
}
