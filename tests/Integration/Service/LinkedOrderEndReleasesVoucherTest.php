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
use OxidEsales\PaymentBase\Adapter\LinkedOrderEndingInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\OxidContractLinkedOrderUpdater;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * OPC-175 / S0 — gap G6. A Mollie webhook `canceled` or `failed` ends the
 * customer's attempt, but OxidContractLinkedOrderUpdater only rewrites
 * OXTRANSSTATUS. The order is not stornoed and its voucher stays used. The
 * not-finished cleanup command never sees the order again (it looks for
 * NOT_FINISHED only), so the coupon is lost for good.
 *
 * The two release tests are RED on purpose until S4. The paid-order test is
 * the guard S4 must keep green.
 *
 * @group integration
 * @group database
 */
#[Group('integration')]
#[Group('database')]
final class LinkedOrderEndReleasesVoucherTest extends TestCase
{
    private const SHOP_ID = 1;

    private Connection $connection;
    /** @var list<array{0: string, 1: string}> table/id pairs to delete */
    private array $cleanup = [];

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
    }

    public function testMarkCancelled_UnpaidOrder_StornosTheOrderAndReleasesItsVoucher(): void
    {
        $orderId = $this->insertOrder(paid: false);
        $voucherId = $this->insertVoucherUsedBy($orderId);

        $this->updater()->markCancelled($orderId);

        self::assertSame('CANCELLED', $this->orderField($orderId, 'OXTRANSSTATUS'));
        self::assertSame('1', $this->orderField($orderId, 'OXSTORNO'), 'G6: the order is not stornoed');
        self::assertSame('', $this->voucherField($voucherId, 'OXORDERID'), 'G6: the voucher stays used');
    }

    public function testMarkFailed_UnpaidOrder_StornosTheOrderAndReleasesItsVoucher(): void
    {
        $orderId = $this->insertOrder(paid: false);
        $voucherId = $this->insertVoucherUsedBy($orderId);

        $this->updater()->markFailed($orderId, 'failed');

        self::assertSame('FAILED', $this->orderField($orderId, 'OXTRANSSTATUS'));
        self::assertSame('1', $this->orderField($orderId, 'OXSTORNO'), 'G6: the order is not stornoed');
        self::assertSame('', $this->voucherField($voucherId, 'OXORDERID'), 'G6: the voucher stays used');
    }

    public function testMarkCancelled_PaidOrder_KeepsItsVoucher(): void
    {
        $orderId = $this->insertOrder(paid: true);
        $voucherId = $this->insertVoucherUsedBy($orderId);

        $this->updater()->markCancelled($orderId);

        self::assertSame($orderId, $this->voucherField($voucherId, 'OXORDERID'));
    }

    private function updater(): OxidContractLinkedOrderUpdater
    {
        return new OxidContractLinkedOrderUpdater(
            ContainerFactory::getInstance()->getContainer()->get(LinkedOrderEndingInterface::class)
        );
    }

    private function orderField(string $orderId, string $column): string
    {
        return (string) $this->connection->fetchOne("SELECT {$column} FROM oxorder WHERE OXID = :id", ['id' => $orderId]);
    }

    private function voucherField(string $voucherId, string $column): string
    {
        return (string) $this->connection->fetchOne("SELECT {$column} FROM oxvouchers WHERE OXID = :id", ['id' => $voucherId]);
    }

    private function insertOrder(bool $paid): string
    {
        $id = substr('opc175_ord_' . bin2hex(random_bytes(8)), 0, 32);
        $this->cleanup[] = ['oxorder', $id];

        $this->connection->insert('oxorder', [
            'OXID' => $id,
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERID' => 'opc175_user',
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXORDERNR' => random_int(1000000, 9999999),
            'OXTRANSSTATUS' => 'NOT_FINISHED',
            'OXBILLEMAIL' => 'opc175@example.com',
            'OXBILLFNAME' => 'Coupon',
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
            'OXPAID' => $paid ? date('Y-m-d H:i:s') : '0000-00-00 00:00:00',
        ]);

        return $id;
    }

    /** A voucher row as core markVouchers() leaves it for this order. */
    private function insertVoucherUsedBy(string $orderId): string
    {
        $id = substr('opc175_vou_' . bin2hex(random_bytes(8)), 0, 32);
        $this->cleanup[] = ['oxvouchers', $id];

        $this->connection->insert('oxvouchers', [
            'OXID' => $id,
            'OXVOUCHERSERIEID' => 'opc175_series',
            'OXVOUCHERNR' => 'OPC175-' . bin2hex(random_bytes(3)),
            'OXORDERID' => $orderId,
            'OXUSERID' => 'opc175_user',
            'OXDATEUSED' => date('Y-m-d'),
            'OXDISCOUNT' => 5.0,
            'OXRESERVED' => time(),
        ]);

        return $id;
    }
}
