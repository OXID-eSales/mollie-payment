<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Produces deep-links into the Mollie merchant dashboard, mode-aware. Used by the admin
 * transaction-history panel to open a payment's Mollie dashboard page in one click.
 *
 * Mollie's dashboard resolves a bare `/payments/{id}` path without needing the organisation id
 * in the URL, redirecting the operator to the right organisation context after login — this
 * mirrors PayPal's `StaticContent::getOrderUrl()` deep-link approach for the same reason (no
 * organisation/account id is available to this module).
 */
final class MollieUrlBuilder
{
    private const DASHBOARD_TEST = 'https://my.mollie.com/dashboard/test-mode/payments/';
    private const DASHBOARD_LIVE = 'https://my.mollie.com/dashboard/payments/';

    public function __construct(private readonly ModuleConfigurationServiceInterface $config)
    {
    }

    public function paymentUrl(string $paymentId): string
    {
        $base = $this->config->isTestMode() ? self::DASHBOARD_TEST : self::DASHBOARD_LIVE;

        return $base . rawurlencode($paymentId);
    }
}
