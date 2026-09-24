<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentBase\Checkout\InFlightCheckoutAttemptResolverInterface;

/**
 * MOL-18: "Order now" clicked twice. PHP's session lock serialises the two POSTs, so the second
 * one arrives after the first has created contract, order and Mollie payment and answered with a
 * redirect. Starting a second attempt used to retire the first (order storno'd) and - because
 * core's sess_challenge still named that order - end in a phantom order row the shopper then paid
 * for. payment-base decides whether an attempt is in flight (open contract with a checkout URL,
 * order still NOT_FINISHED, same basket total); this class only reads the live basket total for it
 * and hands the Mollie checkout URL to replay back to the controller.
 */
final class InFlightCheckoutReplay
{
    public function __construct(
        private readonly InFlightCheckoutAttemptResolverInterface $resolver,
        private readonly SessionAdapterInterface $session,
    ) {
    }

    /**
     * @return string|null the Mollie checkout URL of the attempt already in flight, or null when a
     *                     new attempt is due (no basket in the session counts as "nothing to rejoin")
     */
    public function checkoutUrl(): ?string
    {
        $basket = $this->session->getBasket();
        if (!$basket instanceof Basket) {
            return null;
        }

        return $this->resolver->resolve((float) $basket->getPrice()->getBruttoPrice());
    }
}
