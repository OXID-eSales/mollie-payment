<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\EventSystem\Handler;

use DomainException;
use InvalidArgumentException;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionFailureReporter;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCaptureRequestEvent;
use OxidEsales\Payments\Mollie\Service\CaptureServiceInterface;
use Throwable;

/**
 * Delegates an admin-initiated capture request to {@see CaptureServiceInterface}. Any failure
 * (including the adapter's `CaptureNotSupportedException` for auto-capture methods) surfaces on
 * the event as errorCode/errorMessage — never propagates to the dispatcher — and is reported to
 * the operator through {@see AdminActionFailureReporter}.
 */
final class MollieCaptureRequestHandler implements HandlerInterface
{
    public function __construct(
        private readonly CaptureServiceInterface $captureService,
        private readonly AdminActionFailureReporter $failures,
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return MollieCaptureRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof MollieCaptureRequestEvent) {
            return;
        }

        try {
            $capture = $this->captureService->capture(
                $event->contract,
                $event->amount,
                $event->idempotencyKey,
            );
            $event->setResult($capture->id);
        } catch (InvalidArgumentException $e) {
            $this->fail($event, 'validation_error', $e);
        } catch (DomainException $e) {
            $this->fail($event, 'state_error', $e);
        } catch (Throwable $e) {
            $this->fail($event, 'capture_failed', $e);
        }
    }

    private function fail(MollieCaptureRequestEvent $event, string $code, Throwable $e): void
    {
        $event->setResult(null, $code, $e->getMessage());
        $this->failures->report($event->contract, AdminActionFailureReporter::ACTION_CAPTURE, $e->getMessage());
    }
}
