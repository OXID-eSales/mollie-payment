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
 * per currency/country pair for the lifetime of this request-scoped service instance. The list is
 * the same whatever the shop's capture mode — see {@see CheckoutPaymentService::captureModeFor()}.
 */
final class PaymentMethodListService implements PaymentMethodListServiceInterface
{
    /** @var array<string, list<\OxidEsales\Payments\Mollie\Adapter\Dto\MollieMethodDto>> */
    private array $cache = [];

    public function __construct(
        private readonly MollieMethodsAdapterInterface $methodsAdapter,
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
        //
        // The shop's capture mode is deliberately NOT a filter here. Manual capture is decided per
        // created payment (CheckoutPaymentService::captureModeFor): methods that cannot hold an
        // authorization are offered all the same and simply settle immediately.
        $methods = array_values(array_filter(
            $methods,
            static fn (MollieMethodDto $m): bool => MollieDefinitions::isOfferableInline($m->id),
        ));

        return $this->cache[$cacheKey] = $methods;
    }

    private function cacheKey(string $currency, ?string $country): string
    {
        return strtoupper($currency) . '|' . strtoupper($country ?? '');
    }
}
