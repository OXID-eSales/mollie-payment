<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\ClientConfig\Contract\AllowListedClientConfigProviderInterface;

/**
 * Mollie's answer to payment-base's sealed `paymentClientConfig` query: the two values the browser's
 * Mollie Components need (profile id, test/live). An explicit allow-list built from the module
 * configuration service; the API keys live in the same settings and must never be added here.
 */
final class MollieClientConfigProvider implements AllowListedClientConfigProviderInterface
{
    private const PROVIDER_ID = 'mollie';
    private const PUBLIC_VALUE_NAMES = ['profileId', 'testMode'];

    public function __construct(private readonly ModuleConfigurationServiceInterface $config)
    {
    }

    public function providerId(): string
    {
        return self::PROVIDER_ID;
    }

    /**
     * The keys `publicValues()` may contain; payment-base's guard refuses every other name.
     *
     * @return list<string>
     */
    public function publicValueNames(): array
    {
        return self::PUBLIC_VALUE_NAMES;
    }

    /**
     * Empty without a profile id: the storefront then falls back to Mollie's hosted page.
     *
     * @return array<string, string|bool>
     */
    public function publicValues(): array
    {
        $profileId = $this->config->getProfileId();
        if ($profileId === '') {
            return [];
        }

        return ['profileId' => $profileId, 'testMode' => $this->config->isTestMode()];
    }
}
