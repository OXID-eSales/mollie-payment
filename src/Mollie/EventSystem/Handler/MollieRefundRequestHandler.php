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
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieRefundRequestEvent;
use OxidEsales\Payments\Mollie\Service\RefundServiceInterface;
use Throwable;

/**
 * Delegates an admin-initiated refund request to {@see RefundServiceInterface}. Failures surface
 * on the event as errorCode/errorMessage so the (Sprint 7) admin controller can render them
 * without leaking raw exception text.
 */
final class MollieRefundRequestHandler implements HandlerInterface
{
    public function __construct(private readonly RefundServiceInterface $refundService)
    {
    }

    public static function getHandledEventClass(): string
    {
        return MollieRefundRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof MollieRefundRequestEvent) {
            return;
        }

        try {
            $refund = $this->refundService->refund(
                $event->contract,
                $event->amount,
                $event->reason,
                $event->idempotencyKey,
            );
            $event->setResult($refund->id);
        } catch (InvalidArgumentException $e) {
            $event->setResult(null, 'validation_error', $e->getMessage());
        } catch (DomainException $e) {
            $event->setResult(null, 'state_error', $e->getMessage());
        } catch (Throwable $e) {
            $event->setResult(null, 'refund_failed', $e->getMessage());
        }
    }
}
