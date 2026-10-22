<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Input;

use OxidEsales\PaymentBase\Checkout\Headless\GuestAddress;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\Payments\Mollie\GraphQL\Input\MollieGuestAddressInput;
use OxidEsales\Payments\Mollie\GraphQL\Input\MollieGuestCheckoutInput;
use OxidEsales\Payments\Mollie\GraphQL\Input\MollieGuestInputFactory;
use PHPUnit\Framework\TestCase;

/**
 * 140b B2 — the GraphQL input of an anonymous checkout is a plain carrier (no validation: a refusal must
 * surface as MollieCheckoutError, not as a coercion error); mapping into payment-base's validated
 * GuestDetails happens inside the controller's try block.
 */
final class MollieGuestInputTest extends TestCase
{
    public function testFactory_AllFieldsGiven_CarriesThemUnchanged(): void
    {
        $address = MollieGuestInputFactory::guestAddress(
            'Ada',
            'Lovelace',
            'Main St',
            '1',
            '79098',
            'Freiburg',
            'a7c40f631fc920687.20179984',
            'MRS',
            'Analytical Engines',
            'back door',
            'BW',
            '+49 761 1'
        );

        self::assertSame('Ada', $address->firstName);
        self::assertSame('a7c40f631fc920687.20179984', $address->countryId);
        self::assertSame('BW', $address->stateId);
        self::assertSame('+49 761 1', $address->phone);
    }

    public function testToGuestDetails_FullInput_MapsEveryFieldIncludingTheDeliveryAddress(): void
    {
        $input = MollieGuestInputFactory::guestCheckout('ada@example.com', $this->address('Main St'), $this->address('Side St'));

        $details = $input->toGuestDetails();

        self::assertSame('ada@example.com', $details->email);
        self::assertInstanceOf(GuestAddress::class, $details->invoiceAddress);
        self::assertSame('Main St', $details->invoiceAddress->street);
        self::assertSame('Freiburg', $details->invoiceAddress->city);
        self::assertSame('MRS', $details->invoiceAddress->salutation);
        self::assertSame('Side St', $details->deliveryAddress?->street);
    }

    public function testToGuestDetails_NoDeliveryAddress_LeavesItNull(): void
    {
        $details = MollieGuestInputFactory::guestCheckout('ada@example.com', $this->address('Main St'), null)->toGuestDetails();

        self::assertNull($details->deliveryAddress);
    }

    public function testToGuestDetails_InvalidEmail_IsGuestDetailsRequiredNamingTheFieldNotTheValue(): void
    {
        $input = MollieGuestInputFactory::guestCheckout('not-an-email', $this->address('Main St'), null);

        try {
            $input->toGuestDetails();
            self::fail('payment-base validates the details');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::GUEST_DETAILS_REQUIRED, $e->errorCode);
            self::assertStringContainsString('email', $e->getMessage());
            self::assertStringNotContainsString('not-an-email', $e->getMessage());
        }
    }

    private function address(string $street): MollieGuestAddressInput
    {
        return MollieGuestInputFactory::guestAddress(
            'Ada',
            'Lovelace',
            $street,
            '1',
            '79098',
            'Freiburg',
            'a7c40f631fc920687.20179984',
            'MRS'
        );
    }
}
