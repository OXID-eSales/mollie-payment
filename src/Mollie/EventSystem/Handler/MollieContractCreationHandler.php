<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\ContractCreationHandler;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;

/**
 * Mollie-specific contract-creation handler.
 *
 * Runs as the *first* handler on {@see \OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent}
 * (priority 100). The payment-base Template Method base takes care of common validation,
 * contract creation, and idempotency; this subclass only adds:
 *  - the selected Mollie method + payment id stored as contract metadata;
 *  - dispatch of {@see ContractDraftCompletedEvent}, which triggers payment-base's shared
 *    EarlyOrderCreationHandler (priority 90) so the order number exists before
 *    MollieCheckoutSessionHandler calls Mollie's create-payment API.
 */
final class MollieContractCreationHandler extends ContractCreationHandler
{
    public const METADATA_MOLLIE_METHOD = 'mollie_method';
    public const METADATA_PAYMENT_ID = 'payment_id';

    public static function getHandledEventClass(): string
    {
        return MollieCheckoutSessionRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 100;
    }

    protected function afterContractCreated(
        PaymentContractInterface $contract,
        EventContextInterface $context,
    ): void {
        $method = $context->get('mollieMethod');
        if (is_string($method) && $method !== '') {
            $contract->setMetadata(self::METADATA_MOLLIE_METHOD, $method);
        }

        $contract->setMetadata(self::METADATA_PAYMENT_ID, MollieDefinitions::PAYMENT_ID);
    }

    protected function dispatchContractEvent(
        PaymentContractInterface $contract,
        EventContextInterface $context,
    ): void {
        $this->eventDispatcher->dispatch(new ContractDraftCompletedEvent($contract, $context));
    }
}
