<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint 11 Story 2 (F1) — the webhook event id must identify a DELIVERY, not a payment.
 *
 * Mollie POSTs only `id=tr_xxx`, so the module synthesised its event id from the payment id alone.
 * payment-base claims events through `UNIQUE(OXEVENTID)` on `oe_payments_webhooklogs`
 * (`Version20251031140200.php:311`) — `OXEVENTTYPE` is passed to `claimEvent()` but is NOT part of
 * the constraint. The first delivery for a payment therefore claimed that id forever, and every
 * later delivery for the same payment — including the `paid` one — was answered
 * `200 skipped: Already processed`. Mollie retries only on non-2xx, so the event was gone.
 *
 * Sequences this broke: `authorized → paid` (cards/Klarna two-step), `pending → paid`
 * (bank-style methods, PayPal via Mollie), and every `refunded`/`chargedback` delivery arriving
 * after a `paid` one.
 *
 * The fix belongs here and not in the schema: Mollie owns no migrations (see CLAUDE.md), and the
 * event id is the module's own value to choose.
 */
#[CoversClass(MollieWebhookProcessor::class)]
#[Group('F1')]
final class WebhookEventIdentityTest extends TestCase
{
    private const PAYMENT_ID = 'tr_identity';

    public function testAuthorizedAndPaidOnOnePaymentClaimDifferentEventIds(): void
    {
        $authorized = $this->claimedEventIdFor(status: 'authorized');
        $paid = $this->claimedEventIdFor(status: 'paid');

        self::assertNotSame(
            $authorized,
            $paid,
            'the paid delivery must not collide with the earlier authorized delivery (F1)',
        );
    }

    public function testPendingAndPaidOnOnePaymentClaimDifferentEventIds(): void
    {
        self::assertNotSame(
            $this->claimedEventIdFor(status: 'pending'),
            $this->claimedEventIdFor(status: 'paid'),
        );
    }

    public function testChargebackDoesNotCollideWithThePaidDelivery(): void
    {
        self::assertNotSame(
            $this->claimedEventIdFor(status: 'paid'),
            $this->claimedEventIdFor(status: 'paid', chargedBack: 40.0),
        );
    }

    /**
     * Without a monetary discriminator, `{paymentId}:{type}` alone still collides: two successive
     * partial refunds both map to type `refunded`, so the second would be swallowed exactly as
     * before the fix. This is the assertion that makes Story 2 more than half done.
     */
    public function testSuccessivePartialRefundsClaimDifferentEventIds(): void
    {
        $first = $this->claimedEventIdFor(status: 'paid', refunded: 12.50);
        $second = $this->claimedEventIdFor(status: 'paid', refunded: 20.00);

        self::assertNotSame($first, $second, 'a second partial refund must be able to claim its own row');
    }

    public function testSuccessivePartialChargebacksClaimDifferentEventIds(): void
    {
        self::assertNotSame(
            $this->claimedEventIdFor(status: 'paid', chargedBack: 10.00),
            $this->claimedEventIdFor(status: 'paid', chargedBack: 25.00),
        );
    }

    /**
     * The flip side, and the behaviour we must NOT lose: a genuine redelivery of the same status at
     * the same amount is still the same event and must still be deduped.
     */
    public function testTrueReplayKeepsTheSameEventId(): void
    {
        self::assertSame(
            $this->claimedEventIdFor(status: 'paid', refunded: 12.50),
            $this->claimedEventIdFor(status: 'paid', refunded: 12.50),
        );
    }

    /**
     * Cents, not floats: a float in a string key would let 12.5 and 12.50 diverge into two ids for
     * one refund.
     */
    public function testAmountDiscriminatorUsesIntegerCents(): void
    {
        self::assertSame(
            $this->claimedEventIdFor(status: 'paid', refunded: 12.5),
            $this->claimedEventIdFor(status: 'paid', refunded: 12.50),
        );
    }

    public function testEventIdFitsTheOxeventidColumn(): void
    {
        // oe_payments_webhooklogs.OXEVENTID is VARCHAR(128).
        $eventId = $this->claimedEventIdFor(status: 'paid', refunded: 999999.99);

        self::assertLessThanOrEqual(128, strlen($eventId));
    }

    public function testEventIdStillStartsWithThePaymentIdSoLogsStayGreppable(): void
    {
        self::assertStringStartsWith(self::PAYMENT_ID . ':', $this->claimedEventIdFor(status: 'paid'));
    }

    private function claimedEventIdFor(string $status, float $refunded = 0.0, float $chargedBack = 0.0): string
    {
        $claimed = null;

        $logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $logRepository->method('claimEvent')->willReturnCallback(
            function (string $eventId) use (&$claimed): bool {
                $claimed = $eventId;
                return true;
            },
        );

        $webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $webhookAdapter->method('fetchByWebhookId')->willReturn(new MolliePaymentDto(
            id: self::PAYMENT_ID,
            status: $status,
            amount: new MollieAmountDto('EUR', 100.0),
            amountRefunded: $refunded,
            amountChargedBack: $chargedBack,
        ));

        $processor = new MollieWebhookProcessor(
            $logRepository,
            new NullLogger(),
            $webhookAdapter,
            new MollieStatusMapper(),
            [],
        );

        $processor->process(new WebhookRequest(
            payload: 'id=' . self::PAYMENT_ID,
            signature: '',
            remoteIp: '127.0.0.1',
            receivedAt: new DateTimeImmutable(),
        ));

        self::assertIsString($claimed, 'claimEvent() was not called');

        return $claimed;
    }
}
