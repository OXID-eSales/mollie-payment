<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\EventSystem\Handler;

use DomainException;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCaptureRequestEvent;
use OxidEsales\Payments\Mollie\Service\CaptureServiceInterface;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use Throwable;

/**
 * Delegates an admin-initiated capture request to {@see CaptureServiceInterface}. Any failure
 * (including the adapter's `CaptureNotSupportedException` for auto-capture methods, or Mollie's 422
 * "capture marked as final") surfaces on the event as errorCode/errorMessage and — since MOL-32,
 * mirroring MOL-30's refund handler — as a translated message on the Payment tab's next render,
 * never swallowed: the tab's dispatcher does not read the event result.
 */
final class MollieCaptureRequestHandler implements HandlerInterface
{
    private const MESSAGE_FAILED = 'MOLLIE_ADMIN_CAPTURE_FAILED';

    /**
     * The admin channel is optional so the handler still works where only the event's result is
     * read; with it, a failed capture is told to the admin on the next render (MOL-32 S5).
     */
    public function __construct(
        private readonly CaptureServiceInterface $captureService,
        private readonly ?AdminValidationFeedbackInterface $feedback = null,
        private readonly ?LanguageTranslatorInterface $translator = null,
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
            $event->setResult(null, 'validation_error', $e->getMessage());
            $this->tellTheAdmin($event->contract);
        } catch (DomainException $e) {
            $event->setResult(null, 'state_error', $e->getMessage());
            $this->tellTheAdmin($event->contract);
        } catch (Throwable $e) {
            $event->setResult(null, 'capture_failed', $e->getMessage());
            $this->tellTheAdmin($event->contract);
        }
    }

    private function tellTheAdmin(PaymentContractInterface $contract): void
    {
        $orderId = $contract->getOrderId();
        if ($this->feedback === null || $this->translator === null || $orderId === null || $orderId === '') {
            return;
        }

        $this->feedback->rejectWithMessage($orderId, $this->translator->translateString(self::MESSAGE_FAILED));
    }
}
