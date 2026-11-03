<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieReleaseAuthorizationRequestEvent;
use OxidEsales\Payments\Mollie\Service\FieldValidationFailure;
use OxidEsales\Payments\Mollie\Service\UserDataValidatorInterface;

/**
 * Runs the release the same way for every door. No amount: Mollie releases the whole remainder. The same
 * {@see ReleaseRule} that decides whether the classic tab offers the button decides whether the action runs (without
 * a capture, "release" would cancel the whole hold - that is the void's business). The reason is validated like the
 * other admin free text (MOL-15), then Mollie's event is dispatched straight to the shared dispatcher: payment-base
 * has no generic request event for it.
 */
final class MollieReleaseAction implements ReleaseActionInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contracts,
        private readonly ReleaseRule $releaseRule,
        private readonly UserDataValidatorInterface $userDataValidator,
        private readonly AdminActionRefusals $refusals,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function run(string $orderId, ?string $reason): void
    {
        $contract = $this->contracts->findByOrderId($orderId);
        if ($contract === null || !$this->releaseRule->isReleasable($contract)) {
            throw $this->refusals->notReleasable();
        }
        $this->assertTextAccepted($orderId, $reason);

        $event = new MollieReleaseAuthorizationRequestEvent($contract, $reason);
        $this->events->dispatch($event);
        if (!$event->isSuccess()) {
            throw $this->refusals->releaseFailed();
        }
    }

    /**
     * @throws AdminActionRefusedException
     */
    private function assertTextAccepted(string $orderId, ?string $reason): void
    {
        if ($reason === null) {
            return;
        }
        $failures = $this->userDataValidator->validateFieldMap(
            ['captureReason' => $reason],
            FieldValidationFailure::KIND_ADMIN,
        );
        if ($failures !== []) {
            throw $this->refusals->text($orderId, array_values($failures));
        }
    }
}
