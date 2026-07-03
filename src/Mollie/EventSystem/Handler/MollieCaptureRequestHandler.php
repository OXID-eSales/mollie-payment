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
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCaptureRequestEvent;
use OxidEsales\Payments\Mollie\Service\CaptureServiceInterface;
use Throwable;

/**
 * Delegates an admin-initiated capture request to {@see CaptureServiceInterface}. Any failure
 * (including the adapter's `CaptureNotSupportedException` for auto-capture methods) surfaces on
 * the event as errorCode/errorMessage — never propagates to the dispatcher.
 */
final class MollieCaptureRequestHandler implements HandlerInterface
{
    public function __construct(private readonly CaptureServiceInterface $captureService)
    {
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
            $event->setResult(null, 'validation_error', $e->getMessage());
        } catch (DomainException $e) {
            $event->setResult(null, 'state_error', $e->getMessage());
        } catch (Throwable $e) {
            $event->setResult(null, 'capture_failed', $e->getMessage());
        }
    }
}
