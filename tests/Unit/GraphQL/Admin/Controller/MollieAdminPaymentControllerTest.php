<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Controller;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ValidationError;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Controller\MollieAdminPaymentController;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieReleaseOperationInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Right;
use TheCodingMachine\GraphQLite\Types\ID;

final class MollieAdminPaymentControllerTest extends TestCase
{
    public function testTheMutationNeedsTheCaptureRightAndIsNotLogged(): void
    {
        $method = new ReflectionMethod(MollieAdminPaymentController::class, 'adminMolliePaymentRelease');

        self::assertCount(1, $method->getAttributes(Mutation::class));
        self::assertSame('ADMIN_PAYMENT_CAPTURE', $method->getAttributes(Right::class)[0]->getArguments()[0]);
        self::assertSame([], $method->getAttributes('TheCodingMachine\\GraphQLite\\Annotations\\Logged'));
    }

    public function testAdminMolliePaymentRelease_PassesTheArgumentsAndAnswersWithThePayment(): void
    {
        $service = $this->createMock(MollieReleaseOperationInterface::class);
        $service->expects(self::once())->method('release')->with('o-1', 'reason', 'key-12345')
            ->willReturn(PaymentOperationResult::done(new PaymentContractDetails('c1', 'mollie', 'fulfilled', 100.0, 40.0, 0.0, 'EUR', [])));

        $payload = (new MollieAdminPaymentController($service))->adminMolliePaymentRelease(new ID('o-1'), 'reason', 'key-12345');

        self::assertSame([], $payload->errors());
        self::assertSame('FULFILLED', $payload->paymentContract()?->state());
    }

    public function testAdminMolliePaymentRelease_ARefusalIsAnErrorOfThePayload(): void
    {
        $service = $this->createStub(MollieReleaseOperationInterface::class);
        $service->method('release')->willReturn(PaymentOperationResult::refused(PaymentRefusal::invalid('orderId', 'provider_refused', 'no')));

        $payload = (new MollieAdminPaymentController($service))->adminMolliePaymentRelease(new ID('o-1'));

        self::assertNull($payload->paymentContract());
        self::assertInstanceOf(ValidationError::class, $payload->errors()[0]);
    }
}
