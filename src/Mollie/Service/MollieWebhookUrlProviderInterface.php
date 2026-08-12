<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Supplies the `webhookUrl` field of a Mollie create-payment request.
 *
 * One method, one implementation, one caller ({@see CheckoutPaymentService}) — the interface exists
 * because that caller's unit test has to substitute it, not as speculative abstraction.
 */
interface MollieWebhookUrlProviderInterface
{
    public function getWebhookUrl(): string;
}
