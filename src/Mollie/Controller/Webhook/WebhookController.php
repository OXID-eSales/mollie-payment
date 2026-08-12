<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Webhook;

use DateTimeImmutable;
use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Webhook\WebhookRequest as ProcessorWebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;
use Throwable;

/**
 * Mollie webhook endpoint (`cl=MollieWebhookController`).
 *
 * Thin HTTP layer only (SRP): runs the guard chain, extracts and validates the payment id,
 * hands off to {@see MollieWebhookProcessor}, and maps the result to an HTTP status. All
 * verification, idempotency, and status-driven contract logic live in the processor and its
 * handlers — this controller never touches a contract directly.
 *
 * Mollie retries on any non-2xx response, so the status mapping matters: 200 for anything that
 * completed or was safely skipped (ignored status, duplicate delivery), 4xx for a malformed or
 * unverifiable request (never retried usefully), 5xx only when a handler genuinely failed and a
 * retry might succeed.
 */
class WebhookController extends FrontendController
{
    /**
     * Whether `X-Forwarded-Proto: https` may stand in for a TLS connection at the origin.
     *
     * Sprint 11 Story 6 (F5) shipped this as `false` — anyone can send that header, so honouring it
     * lets anyone tell the HTTPS guard a plaintext request was encrypted. Running it against the real
     * deployment reversed the decision, and the reasoning is worth keeping:
     *
     * The dev/staging shop sits behind Cloudflare. TLS terminates at the proxy, so the origin sees
     * `HTTPS` unset, `SERVER_PORT` 80, and the forwarded header — the normal production topology for
     * a reverse-proxied shop. With trust off, {@see WebhookHttpsGuard} rejected **every** genuine
     * Mollie delivery with `400 tls_required`, verified live. Mollie retries a few times and then
     * gives up, which means no webhooks at all: exactly the silent-order-never-finalizes failure that
     * F1/F2 were fixed to prevent, reintroduced by a hardening measure.
     *
     * The trade is lopsided. What the guard protects is thin: Mollie only ever calls an HTTPS URL, the
     * body is a bare payment id, and the actual verification is the authenticated API re-fetch — so
     * spoofing this header buys an attacker nothing it did not already have on an endpoint that is
     * unauthenticated by design. What breaking it costs is every webhook on every proxied shop.
     *
     * So: honoured by default, with the flag kept so a shop that terminates TLS at the origin can
     * harden it, and a log line whenever the HTTPS verdict rests only on the header — the weak signal
     * stays visible instead of being quietly trusted.
     */
    public const TRUST_PROXY_HEADERS_DEFAULT = true;

    /**
     * Mollie payment ids are `tr_` + alphanumerics. Rejecting anything else costs nothing and, unlike
     * the never-firing token bucket it replaces (F6), actually prevents an unauthenticated POST from
     * buying itself an outbound Mollie API round-trip.
     */
    private const PAYMENT_ID_PATTERN = '/^tr_[A-Za-z0-9]{1,64}$/';

    protected ?MollieWebhookProcessor $processor = null;
    private ?WebhookRequestGuardInterface $guard = null;
    private ?FileLoggerInterface $fileLogger = null;

    public function init(): void
    {
        parent::init();

        $container = ContainerFactory::getInstance()->getContainer();

        try {
            $processor = $container->get(MollieWebhookProcessor::class);
            $this->processor = $processor instanceof MollieWebhookProcessor ? $processor : null;
        } catch (Throwable $e) {
            Registry::getLogger()->error('Mollie webhook processor unavailable', ['error' => $e->getMessage()]);
        }

        try {
            $guard = $container->get(WebhookRequestGuardInterface::class);
            $this->guard = $guard instanceof WebhookRequestGuardInterface ? $guard : null;
        } catch (Throwable $e) {
            // error, not warning (Sprint 11 Story 5 / F3): render() now refuses to serve without a
            // guard chain, so this is the reason the endpoint is down, not a background nuisance.
            Registry::getLogger()->error('Mollie webhook guard chain unavailable', ['error' => $e->getMessage()]);
        }

        try {
            $fileLogger = $container->get('mollie.webhook.file_logger');
            $this->fileLogger = $fileLogger instanceof FileLoggerInterface ? $fileLogger : null;
        } catch (Throwable) {
            // Audit trail is best-effort — never blocks webhook processing (matches the guard/
            // processor seams above: a missing/misconfigured service degrades gracefully).
            $this->fileLogger = null;
        }
    }

    public function render(): string
    {
        $this->setResponseContentType();

        // Three distinct cases, explicitly (Sprint 11 Story 5 / F3). This used to be a `?->` plus a
        // `!== null` test, which quietly made "the guard chain could not be built" mean "the guard
        // chain passed" — the security control was the one thing in this method that failed open.
        // 503 rather than 500: retry-worthy, so a transient container problem does not lose the event.
        $guard = $this->getGuard();
        if ($guard === null) {
            $this->getFileLogger()?->log('webhook_rejected', [
                'reason' => 'guard_unavailable',
                'status' => 503,
            ]);
            $this->sendResponse(503, 'guard_unavailable');
        }

        $guardResult = $guard->check($this->buildGuardRequest());
        if (!$guardResult->ok) {
            $this->getFileLogger()?->log('webhook_rejected', [
                'reason' => $guardResult->reason ?? 'rejected',
                'status' => $guardResult->httpStatus,
            ]);
            $this->sendResponse($guardResult->httpStatus, $guardResult->reason ?? 'rejected');
        }

        $paymentId = $this->extractPaymentId();
        if ($paymentId === null) {
            $this->sendResponse(400, 'missing_id');
        }

        if ($this->processor === null) {
            $this->sendResponse(500, 'processor_unavailable');
        }

        $result = $this->processor->process(new ProcessorWebhookRequest(
            payload: 'id=' . rawurlencode($paymentId),
            signature: '',
            remoteIp: $this->buildGuardRequest()->clientIp,
            receivedAt: new DateTimeImmutable(),
        ));

        $this->getFileLogger()?->log('webhook_processed', [
            'action' => $result->action,
            'success' => $result->isSuccess(),
        ]);

        $this->sendResponse($this->statusCodeFor($result), $result->action);

        // @phpstan-ignore-next-line - unreachable but required for the declared return type
        return '';
    }

    /**
     * Protected for testable subclass override.
     */
    protected function setResponseContentType(): void
    {
        Registry::getUtils()->setHeader('Content-Type: application/json');
    }

    /**
     * Protected for testable subclass override.
     */
    protected function getGuard(): ?WebhookRequestGuardInterface
    {
        return $this->guard;
    }

    /**
     * Protected for testable subclass override.
     */
    protected function getFileLogger(): ?FileLoggerInterface
    {
        return $this->fileLogger;
    }

    /**
     * Protected for testable subclass override.
     */
    protected function buildGuardRequest(): WebhookRequest
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;

        $this->warnIfSchemeRestsOnAForwardedHeader();

        return new WebhookRequest(
            scheme: self::resolveScheme($_SERVER, $this->trustProxyHeaders()),
            clientIp: is_string($remoteAddr) ? $remoteAddr : '',
            contentLength: is_numeric($contentLength) ? (int) $contentLength : 0,
            rawBody: (string) file_get_contents('php://input'),
        );
    }

    /**
     * Whether `X-Forwarded-Proto` may be believed. Overridable seam so a shop that terminates TLS at
     * the origin can harden it; see {@see self::TRUST_PROXY_HEADERS_DEFAULT}.
     */
    protected function trustProxyHeaders(): bool
    {
        return self::TRUST_PROXY_HEADERS_DEFAULT;
    }

    /**
     * Record when "this request was TLS" is a claim made by the client rather than a fact about the
     * connection. Not a rejection — see {@see self::TRUST_PROXY_HEADERS_DEFAULT} for why — but it must
     * not be an invisible assumption either.
     */
    private function warnIfSchemeRestsOnAForwardedHeader(): void
    {
        $httpsVar = $_SERVER['HTTPS'] ?? '';
        $realTls = is_string($httpsVar) && $httpsVar !== '' && $httpsVar !== 'off';
        if ($realTls || !$this->trustProxyHeaders()) {
            return;
        }

        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
            return;
        }

        Registry::getLogger()->debug(
            '[MollieWebhook] treating request as HTTPS on the strength of X-Forwarded-Proto; the '
            . 'connection to this origin was not itself TLS',
            ['remoteIp' => $_SERVER['REMOTE_ADDR'] ?? ''],
        );
    }

    /**
     * Pure so the trust decision is testable without superglobals.
     *
     * @param array<array-key, mixed> $server
     */
    protected static function resolveScheme(array $server, bool $trustProxyHeaders): string
    {
        $httpsServerVar = $server['HTTPS'] ?? '';
        if (is_string($httpsServerVar) && $httpsServerVar !== '' && $httpsServerVar !== 'off') {
            return 'https';
        }

        if ($trustProxyHeaders && ($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return 'https';
        }

        return 'http';
    }

    /**
     * The payment id, or null when it is absent or does not look like a Mollie payment id.
     *
     * The shape check is deliberately here rather than deeper in the stack: verification of a Mollie
     * webhook IS an outbound API round-trip, so an implausible id must be refused before it can buy
     * one (Sprint 11 Story 6 / F6 — this replaces the token-bucket rate limiter that could never
     * fire because its buckets lived in per-request memory).
     */
    protected function extractPaymentId(): ?string
    {
        $value = $this->readRawPaymentId();

        return $value !== null && preg_match(self::PAYMENT_ID_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * Protected for testable subclass override — the Registry touch-point only.
     */
    protected function readRawPaymentId(): ?string
    {
        $value = Registry::getRequest()->getRequestParameter('id');
        $value = is_scalar($value) ? (string) $value : '';

        return $value !== '' ? $value : null;
    }

    private function statusCodeFor(WebhookResult $result): int
    {
        if ($result->isSuccess()) {
            return 200;
        }

        return $result->action === 'signature_invalid' ? 400 : 500;
    }

    /**
     * Protected for testable subclass override.
     */
    protected function sendResponse(int $status, string $action): never
    {
        http_response_code($status);
        echo json_encode(['action' => $action]);
        exit;
    }
}
