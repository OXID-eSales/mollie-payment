<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Input;

use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\GuestDetails;

/**
 * The `guest` argument of mollieCheckoutStart: who an anonymous shopper is (e-mail, invoice address, optional
 * delivery address). See MollieGuestAddressInput for why it is a carrier and why it is prefixed.
 *
 * @since 3.5.0
 */
final readonly class MollieGuestCheckoutInput
{
    public function __construct(
        public string $email,
        public MollieGuestAddressInput $invoiceAddress,
        public ?MollieGuestAddressInput $deliveryAddress = null,
    ) {
    }

    /**
     * @throws HeadlessCheckoutException guest_details_required
     */
    public function toGuestDetails(): GuestDetails
    {
        return new GuestDetails(
            $this->email,
            $this->invoiceAddress->toGuestAddress(),
            $this->deliveryAddress?->toGuestAddress(),
        );
    }
}
