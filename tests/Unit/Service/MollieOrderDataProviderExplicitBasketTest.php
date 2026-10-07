<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use LogicException;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Payments\Mollie\Service\MollieOrderDataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GRAPH-QL / MS1 — the headless checkout has no session basket; the basket being
 * paid is handed in explicitly and the session is never asked.
 */
final class MollieOrderDataProviderExplicitBasketTest extends TestCase
{
    public function testTheBillingAddressComesFromTheGivenBasketNotFromTheSession(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-1');
        $user->method('getFieldData')->willReturnCallback(static fn (string $f): string => [
            'oxfname' => 'Ada', 'oxlname' => 'Lovelace', 'oxusername' => 'ada@example.com',
            'oxstreet' => 'Analytical Way', 'oxstreetnr' => '1', 'oxzip' => '10115', 'oxcity' => 'Berlin',
            'oxcountryid' => 'a7c40f631fc920687.20179984',
        ][$f] ?? '');
        $basket = $this->createMock(Basket::class);
        $basket->method('getBasketUser')->willReturn($user);

        $address = $this->providerWithoutSession()->billingAddress($basket);

        self::assertNotNull($address);
        self::assertSame('Ada', $address->givenName);
        self::assertSame('Berlin', $address->city);
        self::assertSame('DE', $address->country);
    }

    public function testLinesOfTheGivenBasketNeverAskTheSession(): void
    {
        $basket = $this->createMock(Basket::class);
        $basket->method('getCosts')->willReturn(null);

        self::assertSame([], $this->providerWithoutSession()->lines('EUR', 0.0, $basket));
    }

    private function providerWithoutSession(): MollieOrderDataProvider
    {
        return new class extends MollieOrderDataProvider {
            protected function basket(): ?Basket
            {
                throw new LogicException('the session must not be asked when a basket is given');
            }

            protected function basketContents(Basket $basket): iterable
            {
                return [];
            }

            protected function countryIso(string $countryId): string
            {
                return $countryId === 'a7c40f631fc920687.20179984' ? 'DE' : '';
            }
        };
    }
}
