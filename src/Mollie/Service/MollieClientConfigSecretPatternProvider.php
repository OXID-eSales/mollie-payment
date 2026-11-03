<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigSecretPatternProviderInterface;

/**
 * The shapes of Mollie's secrets (OAuth access token, 30-character live/test API key), so that payment-base's
 * ClientConfigGuard refuses such a value before it reaches a browser.
 */
final class MollieClientConfigSecretPatternProvider implements ClientConfigSecretPatternProviderInterface
{
    public function secretValuePatterns(): array
    {
        return [
            '/^access_/',
            '/^(live|test)_[A-Za-z0-9]{30}$/',
        ];
    }
}
