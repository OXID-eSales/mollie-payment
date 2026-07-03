<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;

/**
 * Webhook verification (ISP slice 4 of 4). Mollie sends no signature — verification IS the
 * re-fetch: we ask the API for the payment by id over TLS with our secret key and trust its status.
 */
interface MollieWebhookAdapterInterface
{
    public function fetchByWebhookId(string $paymentId): MolliePaymentDto;
}
