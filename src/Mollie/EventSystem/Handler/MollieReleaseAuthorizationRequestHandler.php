<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\EventSystem\Handler;

use DomainException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieReleaseAuthorizationRequestEvent;
use OxidEsales\Payments\Mollie\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Mollie\Service\ReleaseAuthorizationServiceInterface;
use Throwable;

/**
 * MOL-32 S6 — delegates an admin-initiated release-authorization request to
 * {@see ReleaseAuthorizationServiceInterface}. Failures surface on the event and, like the capture
 * and refund handlers, as a translated message on the Payment tab's next render (never swallowed).
 */
final class MollieReleaseAuthorizationRequestHandler implements HandlerInterface
{
    private const MESSAGE_FAILED = 'MOLLIE_ADMIN_RELEASE_FAILED';

    public function __construct(
        private readonly ReleaseAuthorizationServiceInterface $releaseService,
        private readonly ?AdminValidationFeedbackInterface $feedback = null,
        private readonly ?LanguageTranslatorInterface $translator = null,
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return MollieReleaseAuthorizationRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof MollieReleaseAuthorizationRequestEvent) {
            return;
        }

        try {
            $this->releaseService->release($event->contract, $event->reason);
            $event->setResult(true);
        } catch (DomainException $e) {
            $event->setResult(false, 'state_error', $e->getMessage());
            $this->tellTheAdmin($event->contract);
        } catch (Throwable $e) {
            $event->setResult(false, 'release_failed', $e->getMessage());
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
