<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\PaymentHandler;

use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\PaymentHandler\MolliePaymentHandler;

/**
 * Overrides the two OXID-Registry-bound seams so the handler's
 * dispatch → checkout-url → result logic can be unit-tested without a shop bootstrap.
 */
final class TestableMolliePaymentHandler extends MolliePaymentHandler
{
    protected function prepareOxidBasket(PaymentContextInterface $context): void
    {
        // no-op: no OXID session/basket in unit tests
    }

    protected function buildEventContext(PaymentContextInterface $context): EventContext
    {
        return new EventContext(['paymentId' => MollieDefinitions::PAYMENT_ID]);
    }

    /**
     * Expose the pure metadata reader so it can be unit-tested without a shop bootstrap
     * (buildEventContext itself is stubbed above to avoid the OXID Registry).
     *
     * @return array{selectedMethod: ?string, cardToken: ?string}
     */
    public function exposeMollieParams(PaymentContextInterface $context): array
    {
        return $this->mollieParamsFromContext($context);
    }
}
