<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Input;

use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\GuestAddress;

/**
 * The address half of the `guest` argument of mollieCheckoutStart. A plain carrier: validation belongs to
 * payment-base's GuestAddress, whose refusal the controller turns into a MollieCheckoutError.
 *
 * Same fields as the Stripe module's GuestAddressInput; GraphQL input names must be unique across the schema,
 * hence the prefix. Moving one shared input into payment-base is the DRY follow-up.
 *
 * @since 3.5.0
 */
final readonly class MollieGuestAddressInput
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $street,
        public string $streetNumber,
        public string $zipCode,
        public string $city,
        public string $countryId,
        public string $salutation = '',
        public string $company = '',
        public string $additionalInfo = '',
        public string $stateId = '',
        public string $phone = '',
    ) {
    }

    /**
     * @throws HeadlessCheckoutException guest_details_required
     */
    public function toGuestAddress(): GuestAddress
    {
        return new GuestAddress(
            $this->firstName,
            $this->lastName,
            $this->street,
            $this->streetNumber,
            $this->zipCode,
            $this->city,
            $this->countryId,
            $this->salutation,
            $this->company,
            $this->additionalInfo,
            $this->stateId,
            $this->phone,
        );
    }
}
