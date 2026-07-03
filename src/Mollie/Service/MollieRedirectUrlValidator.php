<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Host-allowlist gate for the Mollie `checkoutUrl` before it is ever handed to a browser
 * redirect (F14 parity with PayPal's `PayerActionUrlValidator`).
 *
 * Mollie's checkoutUrl is returned by an authenticated create-payment API response (TLS + secret
 * key), not by an inbound/forgeable endpoint like PayPal's payer-action HATEOAS link — but the
 * same defense-in-depth applies: without a host check, a compromised Mollie account, a MITM'd
 * session, or a future SDK defect could redirect the shopper to an attacker-controlled domain.
 */
final class MollieRedirectUrlValidator
{
    private const ALLOWED_HOST = 'mollie.com';

    public function isAllowed(string $url): bool
    {
        if (!str_starts_with($url, 'https://')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        return $host === self::ALLOWED_HOST || str_ends_with($host, '.' . self::ALLOWED_HOST);
    }
}
