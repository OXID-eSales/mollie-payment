<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Webhook;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Webhook\Handler\ChargebackCreatedHandler;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentCanceledHandler;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentExpiredHandler;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentFailedHandler;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentPaidHandler;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentRefundedHandler;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookEventHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Story 6 — full inbound pipeline against the real database.
 *
 * Only the outbound Mollie API round-trip is faked (a {@see MollieWebhookAdapterInterface}
 * stub returning a canned {@see MolliePaymentDto}) — everything downstream of that fetch runs
 * for real: the Doctrine-backed WebhookLogRepository (idempotency claim), ContractRepository,
 * TransactionRepository, ContractFulfillmentService (dispatches ContractFulfilledEvent), and
 * payment-base's OrderPaymentCompletedHandler (stamps OXPAID on a real oxorder row).
 *
 * Plain TestCase + explicit row cleanup (not IntegrationTestCase's oxDb transaction wrapper):
 * all repositories here go through the Doctrine connection, a separate session from the legacy
 * oxDb connection IntegrationTestCase wraps in a transaction, so that wrapper never actually
 * covers (or rolls back) any of this test's writes — payment-base's own Doctrine repository
 * tests clean up the same way for the same reason.
 *
 * A live Mollie sandbox call (proving the fetch-by-id itself round-trips against Mollie's API)
 * is deferred to the Sprint 8 E2E per the sprint brief — that requires real sandbox credentials
 * this test environment does not have.
 *
 * @group integration
 * @group database
 */
#[Group('integration')]
#[Group('database')]
final class WebhookEndToEndTest extends TestCase
{
    private const SHOP_ID = 1;

    private Connection $connection;
    private ContractRepositoryInterface $contractRepository;
    private WebhookLogRepositoryInterface $webhookLogRepository;

    /** @var list<string> */
    private array $orderIdsToClean = [];
    /** @var list<string> */
    private array $contractIdsToClean = [];
    /** @var list<string> */
    private array $eventIdsToClean = [];

    protected function setUp(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        $this->connection = $container->get(ConnectionProviderInterface::class)->get();
        $this->contractRepository = $container->get(ContractRepositoryInterface::class);
        $this->webhookLogRepository = $container->get(WebhookLogRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->contractIdsToClean as $contractId) {
            $this->connection->executeStatement(
                'DELETE FROM oe_payments_transaction WHERE OXCONTRACTID = :id',
                ['id' => $contractId],
            );
            $this->connection->executeStatement(
                'DELETE FROM oe_payments_contract WHERE OXID = :id',
                ['id' => $contractId],
            );
        }
        foreach ($this->orderIdsToClean as $orderId) {
            $this->connection->executeStatement('DELETE FROM oxorder WHERE OXID = :id', ['id' => $orderId]);
        }
        foreach ($this->eventIdsToClean as $eventId) {
            $this->connection->executeStatement(
                'DELETE FROM oe_payments_webhooklogs WHERE OXEVENTID = :id',
                ['id' => $eventId],
            );
        }
    }

    public function testPaidWebhook_FinalizesOrder_Returns200(): void
    {
        $paymentId = 'tr_e2e_' . bin2hex(random_bytes(6));
        $orderId = $this->insertOrder();
        $this->createPendingContract($orderId, $paymentId);

        $processor = $this->processor($this->fakeAdapter($paymentId, 'paid'));
        $result = $processor->process($this->webhookRequest($paymentId));

        self::assertTrue($result->isSuccess());
        self::assertSame(200, $this->httpStatusFor($result));

        $contract = $this->contractRepository->findByProviderOrderId($paymentId);
        self::assertNotNull($contract);
        self::assertTrue($contract->getState()->isFulfilled());

        self::assertNotSame('0000-00-00 00:00:00', $this->fetchOxpaid($orderId));

        $transactionTypes = $this->fetchTransactionTypes($contract->getId());
        self::assertCount(1, $transactionTypes);
        self::assertSame(MollieDefinitions::TRANSACTION_TYPE_CAPTURE, $transactionTypes[0]);
    }

    public function testReplay_Returns200Duplicate_NoDoubleSideEffects(): void
    {
        $paymentId = 'tr_e2e_replay_' . bin2hex(random_bytes(6));
        $orderId = $this->insertOrder();
        $this->createPendingContract($orderId, $paymentId);

        $processor = $this->processor($this->fakeAdapter($paymentId, 'paid'));

        $first = $processor->process($this->webhookRequest($paymentId));
        $replay = $processor->process($this->webhookRequest($paymentId));

        self::assertTrue($first->isSuccess());
        self::assertTrue($replay->isSuccess());
        self::assertSame(200, $this->httpStatusFor($replay));
        self::assertSame('skipped', $replay->action);

        $contract = $this->contractRepository->findByProviderOrderId($paymentId);
        self::assertNotNull($contract);
        self::assertCount(1, $this->fetchTransactionTypes($contract->getId()), 'replay must not record a second transaction');
    }

    public function testUnknownStatus_Returns200Ignored(): void
    {
        $paymentId = 'tr_e2e_open_' . bin2hex(random_bytes(6));
        $orderId = $this->insertOrder();
        $this->createPendingContract($orderId, $paymentId);

        $processor = $this->processor($this->fakeAdapter($paymentId, 'open'));
        $result = $processor->process($this->webhookRequest($paymentId));

        self::assertTrue($result->isSuccess());
        self::assertSame('skipped', $result->action);
        self::assertSame(200, $this->httpStatusFor($result));

        $contract = $this->contractRepository->findByProviderOrderId($paymentId);
        self::assertNotNull($contract);
        self::assertTrue($contract->getState()->isPending(), 'an open/pending status must not mutate the contract');
    }

    public function testHandlerThrows_Returns500_SoMollieRetries(): void
    {
        $paymentId = 'tr_e2e_throws_' . bin2hex(random_bytes(6));
        $this->eventIdsToClean[] = $paymentId;

        $throwingHandler = new class implements MollieWebhookEventHandlerInterface {
            public function handledStatuses(): array
            {
                return ['paid'];
            }

            public function handle(WebhookEvent $event): MollieWebhookOutcome
            {
                throw new RuntimeException('simulated handler failure');
            }
        };

        $processor = new MollieWebhookProcessor(
            $this->webhookLogRepository,
            new NullLogger(),
            $this->fakeAdapter($paymentId, 'paid'),
            new MollieStatusMapper(),
            [$throwingHandler],
        );

        $result = $processor->process($this->webhookRequest($paymentId));

        self::assertTrue($result->isFailure());
        self::assertSame(500, $this->httpStatusFor($result));

        $log = $this->webhookLogRepository->findByEventId($paymentId);
        self::assertNotNull($log);
        self::assertSame('failed', $log->getStatus());
    }

    private function processor(MollieWebhookAdapterInterface $adapter): MollieWebhookProcessor
    {
        $container = ContainerFactory::getInstance()->getContainer();

        $handlers = [
            $container->get(PaymentPaidHandler::class),
            $container->get(PaymentFailedHandler::class),
            $container->get(PaymentExpiredHandler::class),
            $container->get(PaymentCanceledHandler::class),
            $container->get(PaymentRefundedHandler::class),
            $container->get(ChargebackCreatedHandler::class),
        ];

        return new MollieWebhookProcessor(
            $this->webhookLogRepository,
            new NullLogger(),
            $adapter,
            new MollieStatusMapper(),
            $handlers,
        );
    }

    private function fakeAdapter(string $paymentId, string $status): MollieWebhookAdapterInterface
    {
        $this->eventIdsToClean[] = $paymentId;

        return new class ($paymentId, $status) implements MollieWebhookAdapterInterface {
            public function __construct(private readonly string $paymentId, private readonly string $status)
            {
            }

            public function fetchByWebhookId(string $paymentId): MolliePaymentDto
            {
                if ($paymentId !== $this->paymentId) {
                    throw new MollieAdapterException('unknown payment id in test double');
                }

                return new MolliePaymentDto(
                    id: $this->paymentId,
                    status: $this->status,
                    amount: new MollieAmountDto('EUR', 42.00),
                );
            }
        };
    }

    private function webhookRequest(string $paymentId): WebhookRequest
    {
        return new WebhookRequest(
            payload: 'id=' . $paymentId,
            signature: '',
            remoteIp: '127.0.0.1',
            receivedAt: new DateTimeImmutable(),
        );
    }

    private function httpStatusFor(WebhookResult $result): int
    {
        if ($result->isSuccess()) {
            return 200;
        }

        return $result->action === 'signature_invalid' ? 400 : 500;
    }

    /**
     * Insert a minimal, already-NOT_FINISHED oxorder row (mirrors what EarlyOrderCreationHandler
     * would have produced at checkout-initiation time).
     */
    private function insertOrder(): string
    {
        $orderId = substr('mollie_e2e_' . bin2hex(random_bytes(6)), 0, 32);
        $this->orderIdsToClean[] = $orderId;

        $this->connection->insert('oxorder', [
            'OXID' => $orderId,
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERID' => 'e2e_test_user',
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXORDERNR' => random_int(1000000, 9999999),
            'OXTRANSID' => '',
            'OXTRANSSTATUS' => 'NOT_FINISHED',
            'OXBILLEMAIL' => 'e2e@example.com',
            'OXBILLFNAME' => 'E2E',
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

        return $orderId;
    }

    private function fetchOxpaid(string $orderId): string
    {
        $value = $this->connection->fetchOne('SELECT OXPAID FROM oxorder WHERE OXID = :id', ['id' => $orderId]);

        return is_string($value) ? $value : '';
    }

    /**
     * Reads recorded transaction types directly via SQL rather than through
     * TransactionRepositoryInterface — that service is deliberately public:false (see
     * services.yaml) to stay consistent with Stripe's binding of the same shared interface in
     * this multi-module dev shop.
     *
     * @return list<string>
     */
    private function fetchTransactionTypes(string $contractId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT OXTYPE FROM oe_payments_transaction WHERE OXCONTRACTID = :contractId',
            ['contractId' => $contractId],
        );

        return array_map(static fn (array $row): string => (string) $row['OXTYPE'], $rows);
    }

    /**
     * Builds a contract in the same state Sprint 4's checkout-initiation chain leaves it in:
     * NOT_FINISHED -> PENDING, with the `payment_authorized` condition attached but not yet
     * fulfilled, and provider/providerOrderId already stamped.
     */
    private function createPendingContract(string $orderId, string $paymentId): PaymentContract
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [
                ['articleId' => 'e2e_article', 'title' => 'E2E Product', 'amount' => 1, 'price' => 42.00, 'vat' => 19],
            ],
            'totalGross' => 42.00,
            'totalNet' => 35.29,
            'totalVat' => 6.71,
            'currency' => 'EUR',
        ]);

        $contract = new PaymentContract(self::SHOP_ID, 'e2e_test_user', $basketSnapshot);
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->setProvider(MollieDefinitions::PROVIDER_NAME, $paymentId);

        $this->contractRepository->save($contract);
        $this->contractIdsToClean[] = $contract->getId();

        return $contract;
    }
}
