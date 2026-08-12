<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\AbstractWebhookProcessor;
use OxidEsales\PaymentBase\Webhook\Exception\WebhookSignatureException;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use Psr\Log\LoggerInterface;

/**
 * Mollie webhook processor.
 *
 * Mollie sends no signature: verification IS the re-fetch (fetch the payment by id over TLS
 * with our secret key and trust the API's status). {@see parseAndValidateRequest()} does exactly
 * that; a not-found/unreachable payment is reported as a {@see WebhookSignatureException} so the
 * template method in {@see AbstractWebhookProcessor} reports it the same way a failed signature
 * check would (400, no side effects, no idempotency claim taken).
 *
 * Event routing is open-for-extension (OCP): new statuses are handled by registering a new
 * {@see MollieWebhookEventHandlerInterface} tagged `mollie.webhook_handler` in services.yaml,
 * without editing this class.
 */
final class MollieWebhookProcessor extends AbstractWebhookProcessor
{
    private const TYPE_CHARGEDBACK = 'chargedback';
    private const TYPE_REFUNDED = 'refunded';

    private ?string $lastContractId = null;

    /**
     * @param iterable<MollieWebhookEventHandlerInterface> $handlers Tagged mollie.webhook_handler services
     */
    public function __construct(
        WebhookLogRepositoryInterface $logRepository,
        LoggerInterface $logger,
        private readonly MollieWebhookAdapterInterface $webhookAdapter,
        private readonly MollieStatusMapper $statusMapper,
        private readonly iterable $handlers,
    ) {
        parent::__construct($logRepository, $logger);
    }

    protected function getProviderName(): string
    {
        return MollieDefinitions::PROVIDER_NAME;
    }

    /**
     * @throws WebhookSignatureException
     */
    protected function parseAndValidateRequest(WebhookRequest $request): WebhookEvent
    {
        $paymentId = $this->extractPaymentId($request->payload);
        if ($paymentId === null) {
            throw new WebhookSignatureException('Missing Mollie payment id in webhook payload');
        }

        try {
            $payment = $this->webhookAdapter->fetchByWebhookId($paymentId);
        } catch (MollieAdapterException $e) {
            throw new WebhookSignatureException(
                sprintf('Could not verify Mollie payment "%s": %s', $paymentId, $e->getMessage()),
                0,
                $e,
            );
        }

        $type = $this->determineEventType($payment);

        return new WebhookEvent(
            id: $this->buildEventId($payment, $type),
            type: $type,
            data: ['object' => $this->toEventObject($payment)],
            created: time(),
        );
    }

    /**
     * Identify the DELIVERY, not the payment (Sprint 11 Story 2 / F1).
     *
     * Mollie sends only `id=tr_xxx`, and payment-base claims events on `UNIQUE(OXEVENTID)` alone —
     * `OXEVENTTYPE` is passed to `claimEvent()` but is not part of the constraint. Using the bare
     * payment id therefore let the first delivery claim it forever, so the decisive `paid` webhook
     * that followed an `authorized`/`pending` one was answered `200 skipped` and never retried.
     *
     * `{paymentId}:{type}` is not sufficient on its own: two successive PARTIAL refunds both map to
     * type `refunded`. The cumulative amount in integer cents discriminates them — cents rather
     * than a float so `12.5` and `12.50` cannot become two ids for one refund.
     *
     * A true replay (same status, same cumulative amount) still produces the same id and is still
     * correctly deduplicated, which is the idempotency actually worth having.
     */
    private function buildEventId(MolliePaymentDto $payment, string $type): string
    {
        $eventId = $payment->id . ':' . $type;

        if ($type === self::TYPE_REFUNDED) {
            return $eventId . ':' . $this->toCents($payment->amountRefunded);
        }

        if ($type === self::TYPE_CHARGEDBACK) {
            return $eventId . ':' . $this->toCents($payment->amountChargedBack);
        }

        return $eventId;
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    protected function processEvent(WebhookEvent $event): WebhookResult
    {
        $this->lastContractId = null;

        foreach ($this->handlers as $handler) {
            if (!in_array($event->type, $handler->handledStatuses(), true)) {
                continue;
            }

            $outcome = $handler->handle($event);
            $this->lastContractId = $outcome->contractId;

            return $outcome->result;
        }

        return WebhookResult::skipped("Unhandled Mollie payment status: {$event->type}");
    }

    protected function getContractIdFromResult(WebhookResult $result): ?string
    {
        return $this->lastContractId;
    }

    private function extractPaymentId(string $payload): ?string
    {
        parse_str($payload, $parsed);
        $id = $parsed['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Determine the routing "status" for the event. Chargebacks and refunds take priority over
     * the raw Mollie status string because Mollie reports both on the SAME payment resource
     * (there is no distinct chargeback/refund object id in the webhook, only the payment id) —
     * a payment can remain `paid` while also carrying a nonzero amountRefunded/amountChargedBack.
     */
    private function determineEventType(MolliePaymentDto $payment): string
    {
        if ($payment->amountChargedBack > 0.0) {
            return self::TYPE_CHARGEDBACK;
        }

        if ($payment->amountRefunded > 0.0) {
            return self::TYPE_REFUNDED;
        }

        return strtolower($this->statusMapper->map($payment->status)->name);
    }

    /**
     * @return array<string, mixed>
     */
    private function toEventObject(MolliePaymentDto $payment): array
    {
        return [
            'id' => $payment->id,
            'status' => $payment->status,
            'amount' => $payment->amount->value,
            'currency' => $payment->amount->currency,
            'amountRefunded' => $payment->amountRefunded,
            'amountChargedBack' => $payment->amountChargedBack,
            'amountRemaining' => $payment->amountRemaining,
            'method' => $payment->method,
            'metadata' => $payment->metadata,
            // Sprint 11 Story 3 (D3): the handlers bound how long a missing contract is treated as
            // "our own commit may still be in flight" by the payment's own age.
            'createdAt' => $payment->createdAt,
        ];
    }
}
