<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Core;

use OxidEsales\Eshop\Application\Model\Shop;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface;
use OxidEsales\Payments\Mollie\Service\PaymentMethodInstaller;
use Throwable;

/**
 * Module activation / deactivation hooks.
 *
 * Database schema lives entirely in the shared `payment-base` package; this class only handles
 * idempotent Mollie payment-method installation + cache/view regeneration. Ported from PayPal.
 */
class Events
{
    public static function onActivate(): void
    {
        self::ensureMolliePaymentMethods();
        self::regenerateViews();
        self::clearTmp();
    }

    public static function onDeactivate(): void
    {
        if (Registry::getConfig()->isAdmin()) {
            self::clearTmp();
        }
    }

    protected static function ensureMolliePaymentMethods(): void
    {
        try {
            $container = ContainerFactory::getInstance()->getContainer();
            /** @var QueryBuilderFactoryInterface $qbFactory */
            $qbFactory = $container->get(QueryBuilderFactoryInterface::class);
            (new PaymentMethodInstaller($qbFactory))->ensureMolliePaymentMethods();
        } catch (Throwable $e) {
            // Sprint 11 Story 8 (F9): the catch stays — activation must never fail — but it stops
            // being silent. Without the payment-method row Mollie simply never appears in checkout,
            // and the old comment's recovery plan ("admin can re-run oe:module:activate") assumed an
            // admin who knows something went wrong. Nothing told them.
            Registry::getLogger()->error(
                '[Mollie] payment-method installation failed during module activation; Mollie will '
                . 'not be offered in checkout. Re-run `oe:module:activate oe_payments_mollie` after '
                . 'fixing the cause.',
                ['error' => $e->getMessage()],
            );
        }
    }

    protected static function regenerateViews(): void
    {
        /** @var Shop $shop */
        $shop = oxNew('oxShop');
        $shop->generateViews();
    }

    protected static function clearTmp(): void
    {
        Registry::getUtils()->oxResetFileCache();
    }
}
