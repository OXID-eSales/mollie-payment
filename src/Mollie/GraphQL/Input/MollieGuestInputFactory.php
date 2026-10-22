<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Input;

use TheCodingMachine\GraphQLite\Annotations\Factory;

/**
 * GraphQLite factories for the `guest` input of mollieCheckoutStart. Static and dependency-free: the
 * container never has to build them.
 *
 * @since 3.5.0
 */
final class MollieGuestInputFactory
{
    #[Factory(name: 'MollieGuestAddressInput', default: true)]
    public static function guestAddress(
        string $firstName,
        string $lastName,
        string $street,
        string $streetNumber,
        string $zipCode,
        string $city,
        string $countryId,
        ?string $salutation = null,
        ?string $company = null,
        ?string $additionalInfo = null,
        ?string $stateId = null,
        ?string $phone = null
    ): MollieGuestAddressInput {
        return new MollieGuestAddressInput(
            $firstName,
            $lastName,
            $street,
            $streetNumber,
            $zipCode,
            $city,
            $countryId,
            (string) $salutation,
            (string) $company,
            (string) $additionalInfo,
            (string) $stateId,
            (string) $phone,
        );
    }

    #[Factory(name: 'MollieGuestCheckoutInput', default: true)]
    public static function guestCheckout(
        string $email,
        MollieGuestAddressInput $invoiceAddress,
        ?MollieGuestAddressInput $deliveryAddress = null
    ): MollieGuestCheckoutInput {
        return new MollieGuestCheckoutInput($email, $invoiceAddress, $deliveryAddress);
    }
}
