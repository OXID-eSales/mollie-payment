<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\DataType;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MolliePaymentDetails;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * The Mollie part of `AdminPaymentContract.mollie` (read-only): what the customer paid with, Mollie's own status of the
 * payment and what a release would give back or has given back. Read live from Mollie, once per answer. When
 * Mollie cannot be read `apiError` says so (`unreachable`, or `no_payment` when the checkout never created one) and
 * every field that only Mollie could answer is null: never a guess.
 */
#[Type(name: 'AdminMolliePaymentDetails')]
final class AdminMolliePaymentDetails
{
    public function __construct(private readonly MolliePaymentDetails $details)
    {
    }

    /** Mollie's payment id (`tr_...`); null before the checkout created one. */
    #[Field]
    public function paymentId(): ?string
    {
        return $this->details->paymentId;
    }

    /** Deep link to the payment in the Mollie dashboard. */
    #[Field]
    public function dashboardUrl(): ?string
    {
        return $this->details->dashboardUrl;
    }

    /** What the customer paid with, translated for the admin ("Credit card", "iDEAL"). */
    #[Field]
    public function methodLabel(): ?string
    {
        return $this->details->methodLabel;
    }

    /** Masked detail of the method, e.g. `Visa •••• 4242`; null for methods without one. */
    #[Field]
    public function methodDetail(): ?string
    {
        return $this->details->methodDetail;
    }

    /** Mollie's status of the payment: open, pending, authorized, paid, canceled, expired, failed. */
    #[Field]
    public function mollieStatus(): ?string
    {
        return $this->details->mollieStatus;
    }

    /** What a release gave back to the customer (the order is fulfilled with less than it authorised); 0 when none. */
    #[Field]
    public function amountReleased(): float
    {
        return $this->details->amountReleased;
    }

    /** What a release would give back now (after one or more partial captures); 0 when it may not be released. */
    #[Field]
    public function amountReleasable(): ?float
    {
        return $this->details->amountReleasable;
    }

    /** The shop is set up for several captures per authorisation (the merchant confirmed it with Mollie). */
    #[Field]
    public function multiCapture(): bool
    {
        return $this->details->multiCapture;
    }

    /** null when Mollie was read; `unreachable` or `no_payment` otherwise. */
    #[Field]
    public function apiError(): ?string
    {
        return $this->details->apiError;
    }
}
