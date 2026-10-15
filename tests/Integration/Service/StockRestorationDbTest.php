<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\OxidStockRestorationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Review 2026-10-14 finding C-M1 — the REAL write path of the full-refund stock restoration:
 * a real oxorder with two oxorderarticles over a real oxarticles row, through the real
 * OrderArticle::updateArticleStock() and the storno UPDATE. The branch logic lives in the unit
 * test; this proves the SQL and the model round-trip against the live schema.
 *
 * @group integration
 * @group database
 */
#[Group('integration')]
#[Group('database')]
final class StockRestorationDbTest extends TestCase
{
    private const SHOP_ID = 1;
    private const START_STOCK = 5.0;

    private Connection $connection;
    /** @var list<array{0: string, 1: string}> table/id pairs to delete */
    private array $cleanup = [];
    /** @var list<int> oxarticles2shop OXMAPOBJECTIDs to delete (EE) */
    private array $mappingCleanup = [];

    protected function setUp(): void
    {
        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $this->connection = $container->get(ConnectionProviderInterface::class)->get();
        } catch (Throwable $e) {
            self::markTestSkipped('OXID container unavailable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as [$table, $id]) {
            $this->connection->executeStatement("DELETE FROM {$table} WHERE OXID = :id", ['id' => $id]);
        }
        foreach ($this->mappingCleanup as $mapId) {
            $this->connection->executeStatement(
                'DELETE FROM oxarticles2shop WHERE OXMAPOBJECTID = :id',
                ['id' => $mapId],
            );
        }
    }

    public function testAFullRestoreBumpsTheRealStockStampsStornoAndIsIdempotent(): void
    {
        $articleId = $this->insertArticle(stock: self::START_STOCK);
        $orderId = $this->insertOrder();
        $rowA = $this->insertOrderArticle($orderId, $articleId, amount: 2.0);
        $rowB = $this->insertOrderArticle($orderId, $articleId, amount: 3.0);

        $service = new OxidStockRestorationService($this->connection, new NullLogger());

        self::assertSame(2, $service->restoreStockForOrder($orderId));

        self::assertSame(
            self::START_STOCK + 5.0,
            (float) $this->connection->fetchOne(
                'SELECT OXSTOCK FROM oxarticles WHERE OXID = :id',
                ['id' => $articleId],
            ),
            'both ordered amounts return to stock',
        );
        foreach ([$rowA, $rowB] as $rowId) {
            self::assertSame(
                1,
                (int) $this->connection->fetchOne(
                    'SELECT OXSTORNO FROM oxorderarticles WHERE OXID = :id',
                    ['id' => $rowId],
                ),
            );
        }

        self::assertSame(
            0,
            $service->restoreStockForOrder($orderId),
            'a second (webhook-retry) restore must be a no-op',
        );
        self::assertSame(
            self::START_STOCK + 5.0,
            (float) $this->connection->fetchOne(
                'SELECT OXSTOCK FROM oxarticles WHERE OXID = :id',
                ['id' => $articleId],
            ),
            'no double restore',
        );

        // The order's money columns stay untouched (accounting/auditing — see the service docblock
        // on why recalculateOrder() must NOT run).
        self::assertSame(
            42.0,
            (float) $this->connection->fetchOne(
                'SELECT OXTOTALORDERSUM FROM oxorder WHERE OXID = :id',
                ['id' => $orderId],
            ),
        );
    }

    private function insertArticle(float $stock): string
    {
        $id = substr('mollie_t_art_' . bin2hex(random_bytes(6)), 0, 32);
        $this->cleanup[] = ['oxarticles', $id];

        $this->connection->insert('oxarticles', [
            'OXID' => $id,
            'OXSHOPID' => self::SHOP_ID,
            'OXACTIVE' => 1,
            'OXARTNUM' => 'mollie-test-' . substr($id, -6),
            'OXTITLE' => 'Stock restoration fixture',
            'OXSTOCK' => $stock,
            'OXSTOCKFLAG' => 1,
            'OXPRICE' => 10.0,
            'OXPARENTID' => '',
            'OXSUBCLASS' => 'oxarticle',
            'OXVARCOUNT' => 0,
            'OXVARSTOCK' => 0,
        ]);

        // EE: without the shop-mapping row the oxv_ view hides the article and Article::load()
        // fails silently inside updateArticleStock() (stock still updates via raw SQL, but
        // onChange() then warns on unloaded fields). Mirror what the admin would have written.
        $mapId = $this->connection->fetchOne('SELECT OXMAPID FROM oxarticles WHERE OXID = :id', ['id' => $id]);
        if ($mapId !== false && $mapId !== null) {
            $this->connection->insert('oxarticles2shop', [
                'OXSHOPID' => self::SHOP_ID,
                'OXMAPOBJECTID' => $mapId,
            ]);
            $this->mappingCleanup[] = (int) $mapId;
        }

        return $id;
    }

    private function insertOrder(): string
    {
        $id = substr('mollie_t_ord_' . bin2hex(random_bytes(6)), 0, 32);
        $this->cleanup[] = ['oxorder', $id];

        $this->connection->insert('oxorder', [
            'OXID' => $id,
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERID' => 'stock_test_user',
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXORDERNR' => random_int(1000000, 9999999),
            'OXTRANSSTATUS' => 'OK',
            'OXBILLEMAIL' => 'stock@example.com',
            'OXBILLFNAME' => 'Stock',
            'OXBILLLNAME' => 'Test',
            'OXBILLSTREET' => 'Test Street',
            'OXBILLSTREETNR' => '1',
            'OXBILLCITY' => 'Test City',
            'OXBILLCOUNTRYID' => 'a7c40f631fc920687.20179984',
            'OXBILLZIP' => '12345',
            'OXBILLSAL' => 'MR',
            'OXPAYMENTTYPE' => MollieDefinitions::PAYMENT_ID,
            'OXTOTALNETSUM' => 35.29,
            'OXTOTALBRUTSUM' => 42.00,
            'OXTOTALORDERSUM' => 42.00,
            'OXCURRENCY' => 'EUR',
            'OXCURRATE' => 1,
            'OXFOLDER' => 'ORDERFOLDER_NEW',
            'OXPAID' => '0000-00-00 00:00:00',
        ]);

        return $id;
    }

    private function insertOrderArticle(string $orderId, string $articleId, float $amount): string
    {
        $id = substr('mollie_t_oa_' . bin2hex(random_bytes(7)), 0, 32);
        $this->cleanup[] = ['oxorderarticles', $id];

        $this->connection->insert('oxorderarticles', [
            'OXID' => $id,
            'OXORDERID' => $orderId,
            'OXARTID' => $articleId,
            'OXAMOUNT' => $amount,
            'OXARTNUM' => 'mollie-test',
            'OXTITLE' => 'Stock restoration fixture',
            'OXSTORNO' => 0,
            'OXORDERSHOPID' => self::SHOP_ID,
        ]);

        return $id;
    }
}
