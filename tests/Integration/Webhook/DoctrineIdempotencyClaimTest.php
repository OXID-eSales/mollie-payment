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

    private function webhookLogRepository(): WebhookLogRepositoryInterface
    {
        $repository = ContainerFactory::getInstance()->getContainer()->get(WebhookLogRepositoryInterface::class);
        self::assertInstanceOf(WebhookLogRepositoryInterface::class, $repository);

        return $repository;
    }
}
