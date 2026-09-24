<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Core\Price;
use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentBase\Checkout\InFlightCheckoutAttemptResolverInterface;
use OxidEsales\Payments\Mollie\Service\InFlightCheckoutReplay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * MOL-18 — the glue between the order controller and payment-base's in-flight resolver: it
 * contributes exactly one fact, the live basket's gross total.
 */
#[CoversClass(InFlightCheckoutReplay::class)]
final class InFlightCheckoutReplayTest extends TestCase
{
    public function testAsksTheResolverWithTheLiveBasketTotalAndReturnsItsAnswer(): void
    {
        $resolver = $this->createMock(InFlightCheckoutAttemptResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with(116.5)
            ->willReturn('https://www.mollie.com/checkout/select-method/tr_1');

        $replay = new InFlightCheckoutReplay($resolver, $this->sessionWith($this->basketWorth(116.5)));

        self::assertSame('https://www.mollie.com/checkout/select-method/tr_1', $replay->checkoutUrl());
    }

    public function testPassesANullAnswerThrough(): void
    {
        $resolver = $this->createMock(InFlightCheckoutAttemptResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);

        $replay = new InFlightCheckoutReplay($resolver, $this->sessionWith($this->basketWorth(116.5)));

        self::assertNull($replay->checkoutUrl());
    }

    public function testWithoutASessionBasketThereIsNothingToRejoin(): void
    {
        $resolver = $this->createMock(InFlightCheckoutAttemptResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        $replay = new InFlightCheckoutReplay($resolver, $this->sessionWith(null));

        self::assertNull($replay->checkoutUrl());
    }

    private function basketWorth(float $gross): Basket
    {
        $price = $this->createMock(Price::class);
        $price->method('getBruttoPrice')->willReturn($gross);
        $basket = $this->createMock(Basket::class);
        $basket->method('getPrice')->willReturn($price);

        return $basket;
    }

    private function sessionWith(?Basket $basket): SessionAdapterInterface
    {
        $session = $this->createMock(SessionAdapterInterface::class);
        $session->method('getBasket')->willReturn($basket);

        return $session;
    }
}
