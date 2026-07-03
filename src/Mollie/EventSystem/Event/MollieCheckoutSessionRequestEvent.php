<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\EventSystem\Event;

use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;

/**
 * Dispatched when the customer places an order with a Mollie method selected.
 *
 * Handlers (in priority order, all tagged `payment.event_handler`):
 *  - MollieContractCreationHandler (priority 100) — creates the DRAFT contract, then
 *    synchronously dispatches the shared ContractDraftCompletedEvent so payment-base's
 *    EarlyOrderCreationHandler (priority 90) mints the NOT_FINISHED order + order number.
 *  - MollieCheckoutSessionHandler (priority 10) — creates the Mollie payment and stores
 *    the checkout URL on the contract + context.
 */
final readonly class MollieCheckoutSessionRequestEvent implements EventInterface
{
    public function __construct(private EventContext $context)
    {
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }
}
