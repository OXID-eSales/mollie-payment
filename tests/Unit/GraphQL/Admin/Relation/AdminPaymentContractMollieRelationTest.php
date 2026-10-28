<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Relation;

use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentContract;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AdminMolliePaymentDetails;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Relation\AdminPaymentContractMollieRelation;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MolliePaymentDetails;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MolliePaymentDetailsReaderInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\ExtendType;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Right;

final class AdminPaymentContractMollieRelationTest extends TestCase
{
    public function testItExtendsTheContractTypeAndNeedsTheViewRight(): void
    {
        $extend = (new ReflectionClass(AdminPaymentContractMollieRelation::class))->getAttributes(ExtendType::class);
        $method = new ReflectionMethod(AdminPaymentContractMollieRelation::class, 'mollie');

        self::assertCount(1, $extend);
        self::assertSame(AdminPaymentContract::class, $extend[0]->getArguments()['class'] ?? $extend[0]->getArguments()[0]);
        self::assertSame('mollie', $method->getAttributes(Field::class)[0]->getArguments()['name']);
        self::assertSame('ADMIN_PAYMENT_VIEW', $method->getAttributes(Right::class)[0]->getArguments()[0]);
    }

    public function testMollie_ReadsByTheContractIdAndWrapsTheDetails(): void
    {
        $asked = [];
        $reader = new class ($asked) implements MolliePaymentDetailsReaderInterface {
            /** @param list<string> $asked */
            public function __construct(private array &$asked)
            {
            }

            public function forContract(string $contractId): ?MolliePaymentDetails
            {
                $this->asked[] = $contractId;

                return new MolliePaymentDetails('tr_1', null, null, null, 'paid', 0.0, 0.0, false, null);
            }
        };

        $details = (new AdminPaymentContractMollieRelation($reader))->mollie($this->contract('c-7'));

        self::assertSame(['c-7'], $asked);
        self::assertInstanceOf(AdminMolliePaymentDetails::class, $details);
        self::assertSame('tr_1', $details->paymentId());
    }

    public function testMollie_IsNullWhenTheContractIsNotMollies(): void
    {
        $reader = $this->createStub(MolliePaymentDetailsReaderInterface::class);
        $reader->method('forContract')->willReturn(null);

        self::assertNull((new AdminPaymentContractMollieRelation($reader))->mollie($this->contract('c-9', 'stripe')));
    }

    private function contract(string $id, string $provider = 'mollie'): AdminPaymentContract
    {
        return new AdminPaymentContract(new PaymentContractDetails($id, $provider, 'committed', 10.0, 10.0, 0.0, 'EUR', []));
    }
}
