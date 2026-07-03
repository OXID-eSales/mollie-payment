<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Adapter;

use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\Payments\Mollie\Adapter\OxidShopOrderService;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * OXID order-service glue against the real shop (needs bootstrap). Covers the guard paths; full
 * order finalization is proven by the checkout integration/E2E.
 *
 * @group integration
 */
final class OxidShopOrderServiceTest extends TestCase
{
    public function testDeleteNotFinishedOrder_UnknownId_ReturnsFalse(): void
    {
        self::assertFalse((new OxidShopOrderService())->deleteNotFinishedOrder('does-not-exist-0000'));
    }

    public function testCreateOrder_WithoutBasketOrUser_ThrowsShopOrderException(): void
    {
        $this->expectException(ShopOrderException::class);
        (new OxidShopOrderService())->createOrder(new CreateOrderRequest(
            sessionId: 'sess-1',
            userId: 'user-1',
            paymentId: MollieDefinitions::PAYMENT_ID,
        ));
    }
}
