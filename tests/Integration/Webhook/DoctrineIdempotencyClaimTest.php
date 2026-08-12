<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Webhook;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Story 3 — proves the idempotency claim is atomic against the real database (not just
 * mocked). {@see WebhookLogRepositoryInterface::claimEvent()} relies on a unique-key INSERT
 * (OXEVENTID) in `oe_payments_webhooklogs`; this test exercises the real Doctrine-backed
 * repository wired by Mollie's services.yaml.
 *
 * @group integration
 * @group database
 */
#[Group('integration')]
#[Group('database')]
final class DoctrineIdempotencyClaimTest extends TestCase
{
    /** @var list<string> */
    private array $eventIdsToClean = [];

    protected function tearDown(): void
    {
        $connection = ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();
        foreach ($this->eventIdsToClean as $eventId) {
            $connection->executeStatement(
                'DELETE FROM oe_payments_webhooklogs WHERE OXEVENTID = :id',
                ['id' => $eventId],
            );
        }
    }

    public function testClaimEvent_IsAtomic(): void
    {
        $repository = $this->webhookLogRepository();
        $eventId = 'tr_idempotency_' . bin2hex(random_bytes(8));
        $this->eventIdsToClean[] = $eventId;

        $firstClaim = $repository->claimEvent($eventId, 'mollie', 'paid');
        $secondClaim = $repository->claimEvent($eventId, 'mollie', 'paid');

        self::assertTrue($firstClaim, 'first delivery must claim the event');
        self::assertFalse($secondClaim, 'replayed delivery must NOT re-claim the same event id');
    }

    public function testClaimEvent_DifferentEventIdsBothClaim(): void
    {
        $repository = $this->webhookLogRepository();
        $suffix = bin2hex(random_bytes(8));
        $this->eventIdsToClean[] = 'tr_a_' . $suffix;
        $this->eventIdsToClean[] = 'tr_b_' . $suffix;

        self::assertTrue($repository->claimEvent('tr_a_' . $suffix, 'mollie', 'paid'));
        self::assertTrue($repository->claimEvent('tr_b_' . $suffix, 'mollie', 'failed'));
    }

    /**
     * Sprint 11 Story 2 (F1) — the finding, proved against the real `UNIQUE(OXEVENTID)` index rather
     * than a mock.
     *
     * The unique key is `OXEVENTID` alone: `OXEVENTTYPE` is passed to `claimEvent()` but is not part of
     * the constraint. So with the bare payment id as the event id, the first delivery for a payment
     * claimed it forever and the decisive later one (`authorized` → **`paid`**) was answered
     * `200 skipped`. Mollie retries only on non-2xx, so it never came back.
     */
    public function testTwoStatusesOnOnePaymentBothClaim_WithDeliveryScopedEventIds(): void
    {
        $repository = $this->webhookLogRepository();
        $paymentId = 'tr_seq_' . bin2hex(random_bytes(8));
        $this->eventIdsToClean[] = $paymentId . ':authorized';
        $this->eventIdsToClean[] = $paymentId . ':paid';

        $authorized = $repository->claimEvent($paymentId . ':authorized', 'mollie', 'authorized');
        $paid = $repository->claimEvent($paymentId . ':paid', 'mollie', 'paid');

        self::assertTrue($authorized, 'the authorized delivery claims its own row');
        self::assertTrue(
            $paid,
            'the paid delivery must be able to claim its own row — before Sprint 11 the bare payment '
            . 'id made this collide with the authorized delivery and the order was never finalized',
        );
    }

    /**
     * The pre-fix behaviour, kept explicit so the regression is unmistakable: the bare payment id
     * really does collide across statuses.
     */
    public function testBarePaymentIdWouldStillCollideAcrossStatuses(): void
    {
        $repository = $this->webhookLogRepository();
        $paymentId = 'tr_collide_' . bin2hex(random_bytes(8));
        $this->eventIdsToClean[] = $paymentId;

        self::assertTrue($repository->claimEvent($paymentId, 'mollie', 'authorized'));
        self::assertFalse(
            $repository->claimEvent($paymentId, 'mollie', 'paid'),
            'OXEVENTTYPE is not part of UNIQUE(OXEVENTID) — this is why the event id has to carry the '
            . 'delivery identity itself',
        );
    }

    /**
     * Two successive partial refunds map to the same type (`refunded`), so the cumulative amount is
     * what separates them.
     */
    public function testSuccessivePartialRefundsBothClaim(): void
    {
        $repository = $this->webhookLogRepository();
        $paymentId = 'tr_refunds_' . bin2hex(random_bytes(8));
        $this->eventIdsToClean[] = $paymentId . ':refunded:1250';
        $this->eventIdsToClean[] = $paymentId . ':refunded:2000';

        self::assertTrue($repository->claimEvent($paymentId . ':refunded:1250', 'mollie', 'refunded'));
        self::assertTrue($repository->claimEvent($paymentId . ':refunded:2000', 'mollie', 'refunded'));
    }

    private function webhookLogRepository(): WebhookLogRepositoryInterface
    {
        $repository = ContainerFactory::getInstance()->getContainer()->get(WebhookLogRepositoryInterface::class);
        self::assertInstanceOf(WebhookLogRepositoryInterface::class, $repository);

        return $repository;
    }
}
