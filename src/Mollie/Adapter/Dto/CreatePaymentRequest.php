<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Immutable input for creating a Mollie payment. The adapter turns this into the SDK create body.
 */
final readonly class CreatePaymentRequest
{
    /**
     * @param array<string, mixed> $metadata
     * @param list<MollieLineDto> $lines
     */
    public function __construct(
        public MollieAmountDto $amount,
        public string $description,
        public string $redirectUrl,
        public ?string $webhookUrl = null,
        public ?string $method = null,
        public array $metadata = [],
        public ?string $captureMode = null,
        // IFRAME-04: single-use card token minted by Mollie Components in the browser
        // (mollie.createToken()). When set, the adapter forces method=creditcard and
        // includes it so Mollie charges the tokenized card instead of showing its
        // hosted method-selection page.
        public ?string $cardToken = null,
        // Orders-API data (billing/shipping address + reconciled lines) required by pay-later
        // methods (Klarna, Riverty, Billie, in3). Null/empty for methods that don't need them.
        public ?MollieAddressDto $billingAddress = null,
        public ?MollieAddressDto $shippingAddress = null,
        public array $lines = [],
    ) {
    }
}
