<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Eshop\Application\Model\Address;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Payments\Mollie\Service\OxidUserFieldReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * MOL-15: the reader maps the logical field names payment-base validates onto OXID's billing
 * columns and, when the shopper selected a separate delivery address, onto that address' columns.
 */
#[CoversClass(OxidUserFieldReader::class)]
final class OxidUserFieldReaderTest extends TestCase
{
    public function testReadsBillingColumnsForEveryLogicalField(): void
    {
        $user = $this->userWith([
            'oxfname' => 'Jane', 'oxlname' => 'Doe', 'oxaddinfo' => 'Rear', 'oxstreet' => 'Main',
            'oxstreetnr' => '1', 'oxzip' => '10117', 'oxcity' => 'Berlin', 'oxcompany' => 'ACME',
            'oxustid' => 'DE123', 'oxfon' => '030', 'oxprivfon' => '0170', 'oxmobfon' => '0171',
            'oxfax' => '032', 'oxusername' => 'jane@example.com',
        ]);
        $reader = new OxidUserFieldReader($user);

        self::assertSame('10117', $reader->readBillingField('postalCode'));
        self::assertSame('0170', $reader->readBillingField('cellPhone'));
        self::assertSame('0171', $reader->readBillingField('personalPhone'));
        self::assertSame('032', $reader->readBillingField('fax'));
        self::assertSame('jane@example.com', $reader->readBillingField('email'));
        self::assertSame('', $reader->readBillingField('unknownField'));
    }

    public function testWithoutASelectedAddressThereIsNoDeliveryPass(): void
    {
        $reader = new OxidUserFieldReader($this->userWith(['oxfname' => 'Jane']));

        self::assertFalse($reader->hasDeliveryAddress());
        self::assertSame('', $reader->readDeliveryField('street'));
    }

    public function testReadsTheSelectedDeliveryAddress(): void
    {
        $address = $this->createMock(Address::class);
        $address->method('getFieldData')->willReturnCallback(
            static fn (string $column) => ['oxstreet' => 'Elm', 'oxzip' => '20095'][$column] ?? null
        );
        $reader = new OxidUserFieldReader($this->userWith(['oxstreet' => 'Main'], $address));

        self::assertTrue($reader->hasDeliveryAddress());
        self::assertSame('Elm', $reader->readDeliveryField('street'));
        self::assertSame('20095', $reader->readDeliveryField('postalCode'));
        self::assertSame('Main', $reader->readBillingField('street'));
    }

    public function testOxidColumnMapsPostalCodeToZip(): void
    {
        self::assertSame('oxzip', OxidUserFieldReader::oxidColumn('postalCode'));
        self::assertSame('oxprivfon', OxidUserFieldReader::oxidColumn('cellPhone'));
        self::assertNull(OxidUserFieldReader::oxidColumn('nope'));
    }

    /**
     * @param array<string, string> $columns
     */
    private function userWith(array $columns, ?Address $selectedAddress = null): User
    {
        $user = $this->createMock(User::class);
        $user->method('getFieldData')->willReturnCallback(static fn (string $column) => $columns[$column] ?? null);
        $user->method('getSelectedAddress')->willReturn($selectedAddress);

        return $user;
    }
}
