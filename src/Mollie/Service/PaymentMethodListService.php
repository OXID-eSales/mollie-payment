<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Payments\Mollie\Adapter\Dto\MethodsListRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieMethodDto;
use OxidEsales\Payments\Mollie\Adapter\MollieMethodsAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Lists the Mollie payment methods to offer on the storefront selector.
 *
 * Currency is gated locally against {@see MollieDefinitions::supportsCurrency()} (this module is
 * EUR-only today — see MOLLIE_DEFINITIONS) before ever calling the Mollie API; the customer's
 * billing country is instead passed straight through to Mollie's own `billingCountry` filter,
 * which already knows which methods (e.g. iDEAL) are meaningful per country. Results are cached
 * per currency/country pair for the lifetime of this request-scoped service instance.
 */
final class PaymentMethodListService implements PaymentMethodListServiceInterface
{
    /** @var array<string, list<\OxidEsales\Payments\Mollie\Adapter\Dto\MollieMethodDto>> */
    private array $cache = [];

    public function __construct(
        private readonly MollieMethodsAdapterInterface $methodsAdapter,
        private readonly ModuleConfigurationServiceInterface $config,
    ) {
    }

    public function listActiveMethods(string $currency, ?string $country = null): array
    {
        if (!MollieDefinitions::supportsCurrency(MollieDefinitions::PAYMENT_ID, $currency)) {
            return [];
        }

        $cacheKey = $this->cacheKey($currency, $country);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $methods = $this->methodsAdapter->listActiveMethods(new MethodsListRequest(
            billingCountry: $country !== null && $country !== '' ? strtoupper($country) : null,
        ));

        // Always drop methods the inline create-payment flow cannot complete (BNPL/voucher need an
        // upfront billing address + order lines → 422 → MOLLIE_CHECKOUT_UNAVAILABLE).
        $methods = array_values(array_filter(
            $methods,
            static fn (MollieMethodDto $m): bool => MollieDefinitions::isOfferableInline($m->id),
        ));

        // Manual-capture shops may only offer capture-capable methods — otherwise Mollie rejects the
        // create-payment (422). Hide the incompatible ones from the inline selector.
        if ($this->config->isManualCapture()) {
            $methods = array_values(array_filter(
                $methods,
                static fn (MollieMethodDto $m): bool => MollieDefinitions::supportsManualCapture($m->id),
            ));
        }

        return $this->cache[$cacheKey] = $methods;
    }

    private function cacheKey(string $currency, ?string $country): string
    {
        return strtoupper($currency) . '|' . strtoupper($country ?? '');
    }
}
