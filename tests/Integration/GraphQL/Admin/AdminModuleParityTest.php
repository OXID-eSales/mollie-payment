<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\GraphQL\Admin;

use OxidEsales\GraphQL\Admin\Audit\DataType\AuditTrail;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder;
use OxidEsales\GraphQL\Admin\Authorization\DataType\RightDefinition;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ConflictError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ValidationError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors;
use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentContract;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStepUpInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The admin API is optional, so the unit suite runs against stand-ins (tests/Support/admin-api-stubs.php). This proves
 * the stand-ins against the real classes whenever those are installed: the members the Mollie admin API calls exist
 * with the signature it assumes (payment-base's approach).
 */
final class AdminModuleParityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(RightDefinition::class)) {
            self::markTestSkipped('oxid-admin-graphql is not installed');
        }
    }

    /**
     * @return iterable<string, array{class-string, string, list<string>, string}>
     */
    public static function membersTheMollieAdminApiCalls(): iterable
    {
        yield 'AuditRecorder::auditedAdmin' => [AuditRecorder::class, 'auditedAdmin', ['string', \Closure::class], 'mixed'];
        yield 'AuditTrail::on' => [AuditTrail::class, 'on', ['string', 'string'], 'self'];
        yield 'AuditTrail::field' => [AuditTrail::class, 'field', ['string'], 'self'];
        yield 'PayloadWithErrors::errors' => [PayloadWithErrors::class, 'errors', [], 'array'];
        yield 'AdminError::errorCode' => [AdminError::class, 'errorCode', [], 'string'];
        yield 'AdminError::message' => [AdminError::class, 'message', [], 'string'];
        yield 'AdminPaymentContract::contractId' => [AdminPaymentContract::class, 'contractId', [], 'string'];
        yield 'AdminStepUpInterface::require' => [AdminStepUpInterface::class, 'require', [], 'void'];
    }

    /**
     * @param class-string $class
     * @param list<string> $parameterTypes
     */
    #[DataProvider('membersTheMollieAdminApiCalls')]
    public function testTheRealClassHasTheMemberWithTheSignatureTheMollieAdminApiCalls(string $class, string $method, array $parameterTypes, string $returnType): void
    {
        $member = new ReflectionMethod($class, $method);

        self::assertTrue($member->isPublic());
        self::assertSame($parameterTypes, array_map(
            static fn (\ReflectionParameter $parameter): string => self::typeName($parameter->getType()),
            array_slice($member->getParameters(), 0, count($parameterTypes)),
        ));
        self::assertSame($returnType, self::typeName($member->getReturnType()));
    }

    public function testConstructorsTheMollieAdminApiCalls(): void
    {
        self::assertSame(['string', 'string', '?string'], self::constructorTypes(ValidationError::class));
        self::assertSame(['string', 'string', '?string'], self::constructorTypes(ConflictError::class));
        self::assertSame(['string'], self::constructorTypes(AuditTrail::class));
    }

    public function testErrorCodesAndTheRightMollieRelies(): void
    {
        self::assertSame('validation_failed', (new ValidationError('f', 'c'))->errorCode());
        self::assertSame('conflict', (new ConflictError('a', 'b'))->errorCode());
        self::assertSame('ADMIN_PAYMENT_CONFIG', PaymentRightsProvider::CONFIG);
        self::assertSame('ADMIN_PAYMENT_VIEW', PaymentRightsProvider::VIEW);
        self::assertTrue((new ReflectionClass(AuditRecorder::class))->isFinal(), 'the audit unit test skips itself against a final recorder');
    }

    /**
     * @return list<string>
     */
    private static function constructorTypes(string $class): array
    {
        return array_map(
            static fn (\ReflectionParameter $parameter): string => self::typeName($parameter->getType()),
            (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [],
        );
    }

    private static function typeName(?\ReflectionType $type): string
    {
        if ($type === null) {
            return 'mixed';
        }
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $prefix = $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '';

            return $prefix . ($name === 'static' ? 'self' : $name);
        }

        return (string) $type;
    }
}
