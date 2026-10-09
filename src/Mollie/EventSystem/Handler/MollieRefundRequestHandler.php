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
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieRefundRequestEvent;
use OxidEsales\Payments\Mollie\Service\Exception\MollieRefundNotYetAvailableException;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\RefundServiceInterface;
use Throwable;

/**
 * Delegates an admin-initiated refund request to {@see RefundServiceInterface}. Failures surface
 * on the event as errorCode/errorMessage and, since MOL-30, as a translated message on the
 * Payment tab's next render - never raw exception text.
 */
final class MollieRefundRequestHandler implements HandlerInterface
{
    private const MESSAGE_CAPTURE_SETTLING = 'MOLLIE_ADMIN_REFUND_CAPTURE_SETTLING';
    private const MESSAGE_FAILED = 'MOLLIE_ADMIN_REFUND_FAILED';

    /**
     * MOL-30: the admin channel is optional so the handler still works where only the event's
     * result is read; with it, a failed refund is told to the admin on the next render of the
     * Payment tab instead of being swallowed (the tab's dispatcher never read the result).
     */
    public function __construct(
        private readonly RefundServiceInterface $refundService,
        private readonly ?AdminValidationFeedbackInterface $feedback = null,
        private readonly ?LanguageTranslatorInterface $translator = null,
    ) {
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
            // Story 3 (Sprint 9): Pass optional description for audit trail.
            $refund = $this->refundService->refund(
                $event->contract,
                $event->amount,
                $event->reason,
                $event->idempotencyKey,
                $event->description,
            );
            $event->setResult($refund->id);
        } catch (MollieRefundNotYetAvailableException $e) {
            $event->setResult(null, 'capture_settling', $e->getMessage());
            $this->tellTheAdmin($event->contract, self::MESSAGE_CAPTURE_SETTLING);
        } catch (InvalidArgumentException $e) {
            $event->setResult(null, 'validation_error', $e->getMessage());
            $this->tellTheAdmin($event->contract, self::MESSAGE_FAILED);
        } catch (DomainException $e) {
            $event->setResult(null, 'state_error', $e->getMessage());
            $this->tellTheAdmin($event->contract, self::MESSAGE_FAILED);
        } catch (Throwable $e) {
            $event->setResult(null, 'refund_failed', $e->getMessage());
            $this->tellTheAdmin($event->contract, self::MESSAGE_FAILED);
        }
    }

    private function tellTheAdmin(PaymentContractInterface $contract, string $messageKey): void
    {
        $orderId = $contract->getOrderId();
        if ($this->feedback === null || $this->translator === null || $orderId === null || $orderId === '') {
            return;
        }

        $this->feedback->rejectWithMessage($orderId, $this->translator->translateString($messageKey));
    }
}
