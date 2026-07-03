<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\EventSystem\Handler;

use DomainException;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCancelAuthorizationRequestEvent;
use OxidEsales\Payments\Mollie\Service\CancelAuthorizationServiceInterface;
use Throwable;

/**
 * Delegates an admin-initiated cancel-authorization request to
 * {@see CancelAuthorizationServiceInterface}.
 */
final class MollieCancelAuthorizationRequestHandler implements HandlerInterface
{
    public function __construct(private readonly CancelAuthorizationServiceInterface $cancelService)
    {
    }

    public static function getHandledEventClass(): string
    {
        return MollieCancelAuthorizationRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof MollieCancelAuthorizationRequestEvent) {
            return;
        }

        try {
            $this->cancelService->cancel($event->contract, $event->reason);
            $event->setResult(true);
        } catch (DomainException $e) {
            $event->setResult(false, 'state_error', $e->getMessage());
        } catch (Throwable $e) {
            $event->setResult(false, 'cancel_failed', $e->getMessage());
        }
    }
}
