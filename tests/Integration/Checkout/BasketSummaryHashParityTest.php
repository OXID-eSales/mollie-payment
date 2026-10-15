<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Checkout;

use OxidEsales\Eshop\Application\Controller\OrderController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Mollie\Controller\MollieOrderController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

/**
 * Review 2026-10-14 finding A-H2 — `MollieOrderController::currentBasketSummaryHash()` mirrors
 * core's PRIVATE `OrderController::getBasketSummaryHash()` byte-for-byte (core gives the module
 * no other way in). Nothing used to pin that mirror: if core ever changes its recipe, every
 * legitimate Mollie order dies with BASKET_ITEMS_CHANGED_ERROR and no test goes red. This test
 * IS the pin: both methods run on the SAME live controller (the module's class chain extends
 * core's OrderController), reading the same session basket, and must answer the same hash.
 *
 * If this test fails or errors (method renamed/removed), core's recipe moved — update the mirror
 * in MollieOrderController AND chase the known spin-off (make core's helpers protected upstream).
 *
 * @group integration
 */
final class BasketSummaryHashParityTest extends TestCase
{
    public function testTheMollieMirrorAnswersExactlyCoresPrivateHash(): void
    {
        try {
            $controller = oxNew(OrderController::class);
            Registry::getSession()->getBasket();
        } catch (Throwable $e) {
            self::markTestSkipped('OXID shop unavailable: ' . $e->getMessage());

            return;
        }

        self::assertInstanceOf(
            MollieOrderController::class,
            $controller,
            'the Mollie class-chain extension must be active for this parity to mean anything',
        );

        // The summary content is irrelevant — the pin is the RECIPE (method + encoding + digest).
        // Both sides read the same session basket, so a core change to getBasketSummary() itself
        // moves both hashes equally and does not fail this test; only recipe drift does.
        // Private methods are not inherited: reflect on the DECLARING core class.
        $core = new ReflectionMethod(
            \OxidEsales\EshopCommunity\Application\Controller\OrderController::class,
            'getBasketSummaryHash',
        );
        $coreHash = (string) $core->invoke($controller);

        $mirror = new ReflectionMethod($controller, 'currentBasketSummaryHash');
        $mirrorHash = (string) $mirror->invoke($controller);

        self::assertNotSame('', $coreHash);
        self::assertSame(
            $coreHash,
            $mirrorHash,
            'core changed its private getBasketSummaryHash() recipe — update '
            . 'MollieOrderController::currentBasketSummaryHash() to match, byte-for-byte',
        );
    }
}
