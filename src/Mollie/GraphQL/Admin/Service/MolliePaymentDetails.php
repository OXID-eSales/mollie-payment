<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

/**
 * What the admin API knows of the Mollie payment behind a contract. The figures and facts that come from Mollie's
 * live payment are null when Mollie could not be read (`apiError` says why): a fact that could not be read is never
 * replaced by a guess. `paymentId` and `amountReleased` come from the shop's own record and stay available.
 */
final readonly class MolliePaymentDetails
{
    /** The contract has no Mollie payment yet (checkout not started). */
    public const ERROR_NO_PAYMENT = 'no_payment';
    /** Mollie could not be read (the payment, not the key, is the question: see the connection check). */
    public const ERROR_UNREACHABLE = 'unreachable';

    public function __construct(
        public ?string $paymentId,
        public ?string $dashboardUrl,
        public ?string $methodLabel,
        public ?string $methodDetail,
        public ?string $mollieStatus,
        public float $amountReleased,
        public ?float $amountReleasable,
        public bool $multiCapture,
        public ?string $apiError,
    ) {
    }
}
