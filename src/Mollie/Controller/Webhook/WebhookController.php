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
            Registry::getLogger()->warning('Mollie webhook guard chain unavailable', ['error' => $e->getMessage()]);
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

        $guardResult = $this->getGuard()?->check($this->buildGuardRequest());
        if ($guardResult !== null && !$guardResult->ok) {
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
        $httpsServerVar = $_SERVER['HTTPS'] ?? '';
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $isHttps = (is_string($httpsServerVar) && $httpsServerVar !== '' && $httpsServerVar !== 'off')
            || $forwardedProto === 'https';

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;

        return new WebhookRequest(
            scheme: $isHttps ? 'https' : 'http',
            clientIp: is_string($remoteAddr) ? $remoteAddr : '',
            contentLength: is_numeric($contentLength) ? (int) $contentLength : 0,
            rawBody: (string) file_get_contents('php://input'),
        );
    }

    /**
     * Protected for testable subclass override.
     */
    protected function extractPaymentId(): ?string
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
