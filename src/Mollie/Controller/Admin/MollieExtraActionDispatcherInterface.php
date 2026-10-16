<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;

/**
 * MOL-32 S6 — Mollie-only admin actions that have no payment-base-generic request event (so they
 * cannot go through {@see \OxidEsales\PaymentBase\EventSystem\Broker\EventBrokerInterface}).
 * Release-authorization dispatches its Mollie event straight to the shared event dispatcher.
 */
interface MollieExtraActionDispatcherInterface
{
    public function release(Order $order, ?string $reason = null): void;
}
