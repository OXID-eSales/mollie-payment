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
use OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\PaymentMethodInstaller;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Review 2026-10-14 finding C-M1 — 127 lines of activation-time DB writes had no test. The
 * creation path runs against a THROWAWAY payment id (never the live Mollie row), the idempotency
 * contract runs against the real one without mutating it.
 *
 * @group integration
 * @group database
 */
#[Group('integration')]
#[Group('database')]
final class PaymentMethodInstallerTest extends TestCase
{
    private Connection $connection;
    private PaymentMethodInstaller $installer;
    /** @var list<string> */
    private array $paymentIdsToClean = [];

    protected function setUp(): void
    {
        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $this->connection = $container->get(ConnectionProviderInterface::class)->get();
            $this->installer = new PaymentMethodInstaller($container->get(QueryBuilderFactoryInterface::class));
        } catch (Throwable $e) {
            self::markTestSkipped('OXID container unavailable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->paymentIdsToClean as $paymentId) {
            $this->connection->executeStatement('DELETE FROM oxpayments WHERE OXID = :id', ['id' => $paymentId]);
            $this->connection->executeStatement(
                'DELETE FROM oxobject2payment WHERE OXPAYMENTID = :id',
                ['id' => $paymentId],
            );
        }
    }

    public function testCreatesThePaymentRowWithTranslationsAndAssignsEveryActiveDeliverySet(): void
    {
        $paymentId = substr('mollietest' . bin2hex(random_bytes(6)), 0, 32);
        $this->paymentIdsToClean[] = $paymentId;

        $exposed = new ExposedPaymentMethodInstaller(
            ContainerFactory::getInstance()->getContainer()->get(QueryBuilderFactoryInterface::class),
        );
        $exposed->create($paymentId, [
            'defaulton' => true,
            'constraints' => ['oxfromamount' => 1.0, 'oxtoamount' => 5000.0, 'oxaddsumtype' => 'abs'],
            'descriptions' => [
                'de' => ['desc' => 'Testzahlart', 'longdesc' => 'Langtext DE'],
                'en' => ['desc' => 'Test payment', 'longdesc' => 'Long text EN'],
            ],
        ]);
        $exposed->assign($paymentId);

        $row = $this->connection->fetchAssociative(
            'SELECT OXACTIVE, OXFROMAMOUNT, OXTOAMOUNT, OXADDSUMTYPE, OXDESC, OXDESC_1 FROM oxpayments WHERE OXID = :id',
            ['id' => $paymentId],
        );
        self::assertIsArray($row, 'the payment row was created');
        self::assertSame(1, (int) $row['OXACTIVE']);
        self::assertSame(1.0, (float) $row['OXFROMAMOUNT']);
        self::assertSame(5000.0, (float) $row['OXTOAMOUNT']);
        self::assertSame('abs', $row['OXADDSUMTYPE']);
        self::assertContains('Testzahlart', [$row['OXDESC'], $row['OXDESC_1']], 'DE description landed in a language column');
        self::assertContains('Test payment', [$row['OXDESC'], $row['OXDESC_1']], 'EN description landed in a language column');

        $activeDeliverySets = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oxdeliveryset WHERE OXACTIVE = 1');
        $assignments = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM oxobject2payment WHERE OXPAYMENTID = :id AND OXTYPE = 'oxdelset'",
            ['id' => $paymentId],
        );
        self::assertSame($activeDeliverySets, $assignments, 'one assignment per active delivery set');
    }

    /**
     * The public entry is idempotent: with the live Mollie payment row present (module active),
     * running it again neither duplicates nor clobbers — admin edits survive re-activation.
     */
    public function testEnsureIsIdempotentOnTheExistingLiveRow(): void
    {
        $before = $this->connection->fetchAssociative(
            'SELECT OXDESC, OXACTIVE FROM oxpayments WHERE OXID = :id',
            ['id' => MollieDefinitions::PAYMENT_ID],
        );
        if (!is_array($before)) {
            self::markTestSkipped('live Mollie payment method not installed in this shop');
        }

        $this->installer->ensureMolliePaymentMethods();

        $after = $this->connection->fetchAssociative(
            'SELECT OXDESC, OXACTIVE FROM oxpayments WHERE OXID = :id',
            ['id' => MollieDefinitions::PAYMENT_ID],
        );
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM oxpayments WHERE OXID = :id',
            ['id' => MollieDefinitions::PAYMENT_ID],
        );

        self::assertSame($before, $after, 're-activation must not clobber the existing row');
        self::assertSame(1, $count);
    }
}

/**
 * Exposes the protected creation/assignment steps so they can run against a throwaway id.
 */
final class ExposedPaymentMethodInstaller extends PaymentMethodInstaller
{
    /**
     * @param array<string, mixed> $definitions
     */
    public function create(string $paymentId, array $definitions): void
    {
        $this->createPaymentMethod($paymentId, $definitions);
    }

    public function assign(string $paymentId): void
    {
        $this->assignPaymentToActiveDeliverySets($paymentId);
    }
}
