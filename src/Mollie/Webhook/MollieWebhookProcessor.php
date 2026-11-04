<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook;

use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
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
use Throwable;

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
        ThrowableSummaryInterface $throwableSummary,
        private readonly MollieWebhookAdapterInterface $webhookAdapter,
        private readonly MollieStatusMapper $statusMapper,
        private readonly iterable $handlers,
    ) {
        parent::__construct($logRepository, $logger, $throwableSummary);
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
                sprintf(
                    'Could not verify Mollie payment "%s": %s',
                    $paymentId,
                    $this->throwableSummary->toLine($e),
                ),
                0,
                $e,
            );
        }

        $changes = $this->moneyChanges($payment);

        return new WebhookEvent(
            id: $this->buildEventId($payment, $changes),
            type: $changes === [] ? $this->statusType($payment) : implode('+', $changes),
            data: ['object' => $this->toEventObject($payment, $changes)],
            created: time(),
        );
    }

    /**
     * Identify the DELIVERY, not the payment.
     *
     * Mollie sends only `id=tr_xxx`, and payment-base claims events on `UNIQUE(OXEVENTID)` alone —
     * `OXEVENTTYPE` is passed to `claimEvent()` but is not part of the constraint. Using the bare
     * payment id therefore let the first delivery claim it forever, so the decisive `paid` webhook
     * that followed an `authorized`/`pending` one was answered `200 skipped` and never retried.
     *
     * The id says what CHANGED on the payment: `{paymentId}:refunded:{cents}` and `{paymentId}:chargedback:{cents}`,
     * both parts when both amounts are set (`tr_x:refunded:1000:chargedback:3000`). Two successive partial refunds
     * differ by the cumulative amount; a refund that arrives after a chargeback differs from the chargeback's own
     * delivery by its `refunded` part (VR-26 M2: it used to be typed `chargedback` with the chargeback's unchanged id,
     * answered `duplicate` and never booked). Cents rather than a float so `12.5` and `12.50` cannot become two ids.
     *
     * A true replay (same status, same cumulative amounts) still produces the same id and is still
     * correctly deduplicated, which is the idempotency actually worth having.
     *
     * @param list<string> $changes {@see moneyChanges()}
     */
    private function buildEventId(MolliePaymentDto $payment, array $changes): string
    {
        if ($changes === []) {
            return $payment->id . ':' . $this->statusType($payment);
        }

        $eventId = $payment->id;
        foreach ($changes as $change) {
            $amount = $change === self::TYPE_REFUNDED ? $payment->amountRefunded : $payment->amountChargedBack;
            $eventId .= ':' . $change . ':' . $this->toCents($amount);
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

        $outcomes = [];
        $failure = null;
        foreach ($this->routingTypes($event) as $type) {
            // Every money change of the delivery is handled even when an earlier one failed: a failed refund
            // listing must not leave the chargeback of the same delivery unbooked. The first failure is rethrown
            // afterwards so the delivery still fails and Mollie retries it.
            try {
                $outcomes[] = $this->handleType($event, $type);
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $this->combine($outcomes);
    }

    /**
     * @return list<string> the statuses whose handlers run for this delivery: the money changes it carries, or its type
     */
    private function routingTypes(WebhookEvent $event): array
    {
        $changes = $event->getObject()['changes'] ?? [];
        if (!is_array($changes) || $changes === []) {
            return [$event->type];
        }

        return array_values(array_filter($changes, is_string(...)));
    }

    private function handleType(WebhookEvent $event, string $type): MollieWebhookOutcome
    {
        foreach ($this->handlers as $handler) {
            if (in_array($type, $handler->handledStatuses(), true)) {
                return $handler->handle($event);
            }
        }

        return MollieWebhookOutcome::of(WebhookResult::skipped("Unhandled Mollie payment status: {$type}"));
    }

    /**
     * One answer for the delivery: a failure wins (Mollie must retry), then a success (something was booked), else the
     * first skip. The contract id is the first one a handler reported.
     *
     * @param list<MollieWebhookOutcome> $outcomes
     */
    private function combine(array $outcomes): WebhookResult
    {
        $answer = $outcomes[0];
        foreach ($outcomes as $outcome) {
            $this->lastContractId ??= $outcome->contractId;
            if ($outcome->result->isFailure()) {
                $answer = $outcome;
                break;
            }
            if ($outcome->result->action !== 'skipped' && $answer->result->action === 'skipped') {
                $answer = $outcome;
            }
        }

        return $answer->result;
    }

    protected function getContractIdFromResult(WebhookResult $result): ?string
    {
        return $this->lastContractId;
    }

    /**
     * The contract the last processed delivery touched (null when it touched none), for the delivery log line.
     */
    public function contractIdOfLastDelivery(): ?string
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
     * What changed on the payment's money, in a fixed order. Mollie reports refunds and chargebacks on the SAME payment
     * resource (there is no distinct refund/chargeback object id in the webhook, only the payment id) — a payment can
     * remain `paid` while also carrying a nonzero amountRefunded/amountChargedBack, and both can be set at once.
     *
     * @return list<string> `refunded` and/or `chargedback`; empty when neither amount is set
     */
    private function moneyChanges(MolliePaymentDto $payment): array
    {
        $changes = [];
        if ($payment->amountRefunded > 0.0) {
            $changes[] = self::TYPE_REFUNDED;
        }
        if ($payment->amountChargedBack > 0.0) {
            $changes[] = self::TYPE_CHARGEDBACK;
        }

        return $changes;
    }

    private function statusType(MolliePaymentDto $payment): string
    {
        return strtolower($this->statusMapper->map($payment->status)->name);
    }

    /**
     * @param list<string> $changes {@see moneyChanges()}
     *
     * @return array<string, mixed>
     */
    private function toEventObject(MolliePaymentDto $payment, array $changes): array
    {
        return [
            'changes' => $changes,
            'id' => $payment->id,
            'status' => $payment->status,
            'amount' => $payment->amount->value,
            'currency' => $payment->amount->currency,
            'amountRefunded' => $payment->amountRefunded,
            'amountChargedBack' => $payment->amountChargedBack,
            'amountRemaining' => $payment->amountRemaining,
            'method' => $payment->method,
            'metadata' => $payment->metadata,
            // The handlers bound how long a missing contract is treated as
            // "our own commit may still be in flight" by the payment's own age.
            'createdAt' => $payment->createdAt,
        ];
    }
}
