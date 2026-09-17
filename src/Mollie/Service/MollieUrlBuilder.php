<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Produces deep-links into the Mollie merchant dashboard. Used by the admin transaction-history
 * panel to open a payment's Mollie dashboard page in one click.
 *
 * The link is mode-agnostic on purpose: Mollie's dashboard no longer encodes test/live in the URL
 * path (the mode is a toggle inside the dashboard), so the same `/dashboard/payments/{id}` page
 * serves both test and live payments. Emitting a `test-mode` segment produced a dead link.
 *
 * Mollie's dashboard resolves a bare `/payments/{id}` path without needing the organisation id
 * in the URL, redirecting the operator to the right organisation context after login — this
 * mirrors PayPal's `StaticContent::getOrderUrl()` deep-link approach for the same reason (no
 * organisation/account id is available to this module).
 */
final class MollieUrlBuilder
{
    private const DASHBOARD_PAYMENTS = 'https://my.mollie.com/dashboard/payments/';

    public function paymentUrl(string $paymentId): string
    {
        return self::DASHBOARD_PAYMENTS . rawurlencode($paymentId);
    }
}
