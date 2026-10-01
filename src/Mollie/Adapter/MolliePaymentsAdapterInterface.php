<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;

/**
 * Payment lifecycle operations (ISP slice 1 of 4). Consumers depend on this, never on the
 * concrete LazyMollieAdapter or the Mollie SDK.
 */
interface MolliePaymentsAdapterInterface
{
    public function createPayment(CreatePaymentRequest $request): MolliePaymentDto;

    public function getPayment(string $paymentId): MolliePaymentDto;

    public function cancelPayment(string $paymentId): MolliePaymentDto;

    /**
     * Release the uncaptured remainder of an `authorized` payment (Mollie's
     * `release-authorization` endpoint). Releases the whole hold when nothing was captured yet,
     * and only what is left after partial captures otherwise. Mollie accepts the release
     * asynchronously; the payment resource follows a moment later.
     */
    public function releaseAuthorization(string $paymentId): void;
}
