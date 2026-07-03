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

        return new WebhookEvent(
            id: $payment->id,
            type: $this->determineEventType($payment),
            data: ['object' => $this->toEventObject($payment)],
            created: time(),
        );
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
            return 'chargedback';
        }

        if ($payment->amountRefunded > 0.0) {
            return 'refunded';
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
        ];
    }
}
