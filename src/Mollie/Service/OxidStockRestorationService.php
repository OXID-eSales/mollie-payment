<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\OrderArticle;
use OxidEsales\PaymentBase\Service\StockRestorationServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * OXID implementation of StockRestorationServiceInterface.
 *
 * Sprint 24: Implements same logic as OrderArticle::storno() but for all
 * articles in an order. Used when processing full refunds.
 *
 * Reference: source/Application/Controller/Admin/OrderArticle.php::storno()
 *
 * SOLID Principles:
 * - SRP: Only handles stock restoration logic
 * - OCP: Can be extended for different stock strategies
 * - DIP: Depends on abstractions (Connection, LoggerInterface)
 *
 * @since 2.0.0
 */
class OxidStockRestorationService implements StockRestorationServiceInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly bool $useStock = true,
        private readonly bool $allowNegativeStock = false
    ) {
    }

    public function restoreStockForOrder(string $orderId): int
    {
        /** @var Order $order */
        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            $this->logger->warning('Order not found for stock restoration', ['orderId' => $orderId]);
            return 0;
        }

        $orderArticles = $order->getOrderArticles();
        $processedCount = 0;

        /** @var OrderArticle $orderArticle */
        foreach ($orderArticles as $orderArticle) {
            if ($this->processOrderArticle($orderArticle)) {
                $processedCount++;
            }
        }

        // Log stock restoration results.
        // NOTE: Do NOT call $order->recalculateOrder() here.
        // recalculateOrder() rebuilds totals from a virtual basket excluding storno'd articles.
        // Since all articles are now storno'd, the basket would be empty and all totals
        // would be overwritten with zeros (oxtotalordersum, oxtotalbrutsum, etc.).
        // Order totals must remain intact after refund for accounting/auditing purposes.
        if ($processedCount > 0) {
            $this->logger->info('Stock restored for order', [
                'orderId' => $orderId,
                'articlesProcessed' => $processedCount,
            ]);
        }

        return $processedCount;
    }

    /**
     * Process a single order article: restore stock and mark as storno.
     *
     * Logic from OrderArticle::storno():
     * 1. Skip if already storno'd
     * 2. Restore stock if blUseStock is enabled
     * 3. Update oxstorno flag to 1
     */
    private function processOrderArticle(OrderArticle $orderArticle): bool
    {
        // Skip if already storno'd (prevents double-restore)
        /** @phpstan-ignore-next-line OXID core: magic property oxorderarticles__oxstorno->value */
        if ((int) $orderArticle->oxorderarticles__oxstorno->value === 1) {
            return false;
        }

        /** @phpstan-ignore-next-line OXID core: magic property oxorderarticles__oxamount->value */
        $amount = (float) $orderArticle->oxorderarticles__oxamount->value;
        $orderArticleId = $orderArticle->getId();

        // Restore stock if stock management is enabled
        // This mirrors OrderArticle::storno() logic with $sStockSign = 1
        if ($this->useStock && $amount > 0) {
            $orderArticle->updateArticleStock($amount, $this->allowNegativeStock);
        }

        // Mark as storno'd via direct SQL (same as OrderArticle::storno())
        $this->connection->executeStatement(
            'UPDATE oxorderarticles SET oxstorno = 1 WHERE oxid = :oxid',
            ['oxid' => $orderArticleId]
        );

        $this->logger->debug('Order article storno processed', [
            'orderArticleId' => $orderArticleId,
            'amount' => $amount,
            'stockRestored' => $this->useStock,
        ]);

        return true;
    }
}
