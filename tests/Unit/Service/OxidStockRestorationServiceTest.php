<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\OrderArticle;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Payments\Mollie\Service\OxidStockRestorationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Review 2026-10-14 finding C-M1 — 118 lines of refund-path stock restoration (mirroring core's
 * OrderArticle::storno()) had no test at all; RefundServiceTest only proved the INTERFACE mock was
 * called. These tests pin the branch logic: the already-storno'd skip (double-restore protection),
 * the useStock and zero-amount gates, the allowNegativeStock pass-through, and the storno UPDATE.
 * The real DB write path is covered by Integration\Service\StockRestorationDbTest.
 */
#[CoversClass(OxidStockRestorationService::class)]
final class OxidStockRestorationServiceTest extends TestCase
{
    public function testAMissingOrderRestoresNothingAndAnswersZero(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $service = $this->service($connection, order: null);

        self::assertSame(0, $service->restoreStockForOrder('gone'));
    }

    public function testFreshArticlesAreRestoredAndStornodWhileAlreadyStornodOnesAreSkipped(): void
    {
        $fresh = new RecordingOrderArticle('art-fresh', amount: 2.0, storno: 0);
        $done = new RecordingOrderArticle('art-done', amount: 3.0, storno: 1);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')
            ->with(
                'UPDATE oxorderarticles SET oxstorno = 1 WHERE oxid = :oxid',
                ['oxid' => 'art-fresh'],
            );

        $service = $this->service($connection, $this->orderWith($fresh, $done));

        self::assertSame(1, $service->restoreStockForOrder('order-1'));
        self::assertSame([[2.0, false]], $fresh->stockCalls, 'restore the ordered amount, no negative stock');
        self::assertSame([], $done->stockCalls, 'a storno\'d article must never be restored twice');
    }

    public function testWithStockManagementOffTheArticleIsStillStornodButStockUntouched(): void
    {
        $article = new RecordingOrderArticle('art-1', amount: 2.0, storno: 0);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement');

        $service = $this->service($connection, $this->orderWith($article), useStock: false);

        self::assertSame(1, $service->restoreStockForOrder('order-1'));
        self::assertSame([], $article->stockCalls);
    }

    public function testAZeroAmountArticleIsStornodWithoutAStockWrite(): void
    {
        $article = new RecordingOrderArticle('art-1', amount: 0.0, storno: 0);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement');

        $service = $this->service($connection, $this->orderWith($article));

        self::assertSame(1, $service->restoreStockForOrder('order-1'));
        self::assertSame([], $article->stockCalls);
    }

    public function testAllowNegativeStockIsHandedThroughToTheStockUpdate(): void
    {
        $article = new RecordingOrderArticle('art-1', amount: 1.5, storno: 0);
        $connection = $this->createMock(Connection::class);

        $service = $this->service($connection, $this->orderWith($article), allowNegativeStock: true);
        $service->restoreStockForOrder('order-1');

        self::assertSame([[1.5, true]], $article->stockCalls);
    }

    private function orderWith(OrderArticle ...$articles): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderArticles')->willReturn($articles);

        return $order;
    }

    private function service(
        Connection $connection,
        ?Order $order,
        bool $useStock = true,
        bool $allowNegativeStock = false,
    ): OxidStockRestorationService {
        return new class ($connection, $order, $useStock, $allowNegativeStock) extends OxidStockRestorationService {
            public function __construct(
                Connection $connection,
                private readonly ?Order $order,
                bool $useStock,
                bool $allowNegativeStock,
            ) {
                parent::__construct($connection, new NullLogger(), $useStock, $allowNegativeStock);
            }

            protected function loadOrder(string $orderId): ?Order
            {
                return $this->order;
            }
        };
    }
}

/**
 * An order article whose stock side effect is recorded instead of hitting the DB; the magic
 * columns the service reads are plain Field values, as on a loaded model.
 */
final class RecordingOrderArticle extends OrderArticle
{
    /** @var list<array{0: float, 1: bool}> */
    public array $stockCalls = [];

    public function __construct(private readonly string $articleRowId, float $amount, int $storno)
    {
        // Intentionally does NOT call parent::__construct() — no OXID bootstrap needed.
        $this->oxorderarticles__oxamount = new Field($amount);
        $this->oxorderarticles__oxstorno = new Field($storno);
    }

    public function getId()
    {
        return $this->articleRowId;
    }

    public function updateArticleStock($addAmount, $allowNegativeStock = false)
    {
        $this->stockCalls[] = [(float) $addAmount, (bool) $allowNegativeStock];
    }
}
