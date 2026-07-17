<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Support;

use OxidEsales\Eshop\Core\Registry;

/**
 * Helper for unit tests whose fixtures extend a concrete Mollie class-chain EXTENSION
 * (Core\ViewConfig, Controller\PaymentController, Controller\MollieOrderController).
 *
 * Instantiating such a class by its concrete name re-enters OXID's ModuleChainsGenerator
 * while the class is still mid-load and fatals ("…_parent not found") whenever two or more
 * OTHER modules also extend the same core class (e.g. Stripe / OnePageCheckout / OpalReturns /
 * PayPal all active in one shop). Building the chain first through the real generator — whose
 * trigger is getClassName(), NOT a chain member — creates every `_parent` alias up front, so the
 * subsequent concrete instantiation loads cleanly. A no-op when Mollie is the only extension of
 * that class (the isolated CI unit job), so it is safe to call unconditionally.
 */
trait PreloadsModuleClassChain
{
    /**
     * @param string $shopClassKey OXID shop-class key, e.g. 'payment', 'order', 'oxviewconfig'.
     */
    protected static function preloadModuleClassChain(string $shopClassKey): void
    {
        Registry::getUtilsObject()->getClassName($shopClassKey);
    }
}
