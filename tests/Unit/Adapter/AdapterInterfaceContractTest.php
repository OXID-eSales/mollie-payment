<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter;

use OxidEsales\Payments\Mollie\Adapter\MollieCaptureAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieMethodsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * ISP + boundary contract: each adapter interface stays small (<=4 methods) and exposes only
 * DTO/scalar types — no Mollie SDK type may appear in a signature.
 */
final class AdapterInterfaceContractTest extends TestCase
{
    /**
     * @return list<array{class-string}>
     */
    public static function interfaceProvider(): array
    {
        return [
            [MolliePaymentsAdapterInterface::class],
            [MollieCaptureAdapterInterface::class],
            [MollieRefundAdapterInterface::class],
            [MollieWebhookAdapterInterface::class],
            [MollieMethodsAdapterInterface::class],
        ];
    }

    /**
     * @param class-string $interface
     */
    #[DataProvider('interfaceProvider')]
    public function testEachAdapterInterface_HasAtMostFourMethods(string $interface): void
    {
        $methods = (new ReflectionClass($interface))->getMethods();
        self::assertLessThanOrEqual(4, count($methods), "$interface exceeds the 4-method ISP budget");
    }

    /**
     * @param class-string $interface
     */
    #[DataProvider('interfaceProvider')]
    public function testInterfaces_DeclareOnlyDtoReturnTypes_NoSdkTypes(string $interface): void
    {
        foreach ((new ReflectionClass($interface))->getMethods() as $method) {
            foreach ($this->typeNames($method) as $type) {
                self::assertStringNotContainsString(
                    'Mollie\\Api',
                    $type,
                    "$interface::{$method->getName()} exposes an SDK type: $type",
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function typeNames(ReflectionMethod $method): array
    {
        $types = [];
        $return = $method->getReturnType();
        if ($return instanceof ReflectionNamedType) {
            $types[] = $return->getName();
        }
        foreach ($method->getParameters() as $parameter) {
            $paramType = $parameter->getType();
            if ($paramType instanceof ReflectionNamedType) {
                $types[] = $paramType->getName();
            }
        }
        return $types;
    }
}
