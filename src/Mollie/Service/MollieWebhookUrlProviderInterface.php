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

    /**
     * The URL that applies for a given override ('' = none, the shop's own webhook controller). The admin API
     * asks it for the override it has just read from the settings, which the cached configuration service
     * would not show yet right after a change.
     */
    public function urlFor(string $override): string;
}
