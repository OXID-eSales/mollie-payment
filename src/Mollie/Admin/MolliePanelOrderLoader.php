<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\Eshop\Application\Model\Order;

/**
 * Thin OXID-bounded seam used by {@see MolliePaymentPanelProvider} to load an {@see Order} by id.
 * Kept separate (mirrors PayPal's `PayPalPanelOrderLoader` / Stripe's `StripePanelOrderLoader`) so
 * the provider's own logic is unit-testable without the shop bootstrap — tests inject a fake
 * loader instead of exercising `oxNew()`.
 */
class MolliePanelOrderLoader
{
    public function loadById(string $orderId): ?Order
    {
        if ($orderId === '') {
            return null;
        }

        /** @phpstan-ignore-next-line function.notFound OXID core oxNew */
        $order = oxNew(Order::class);
        /** @var Order $order */
        return $order->load($orderId) ? $order : null;
    }
}
