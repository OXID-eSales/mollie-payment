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
}
