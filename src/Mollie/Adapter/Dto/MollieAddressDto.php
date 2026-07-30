<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * Immutable Mollie address (billing or shipping). Required by pay-later methods (Klarna, Riverty,
 * Billie, in3). `country` is an ISO-3166 alpha-2 code. Empty required fields make {@see isComplete()}
 * false so the caller can hide/skip methods that need a full address (see MOLLIE-ORDERS-API scope D3).
 */
final readonly class MollieAddressDto
{
    public function __construct(
        public string $givenName,
        public string $familyName,
        public string $email,
        public string $streetAndNumber,
        public string $postalCode,
        public string $city,
        public string $country,
    ) {
    }

    /**
     * Mollie requires givenName, familyName, streetAndNumber, city, postalCode, country and (for
     * pay-later) a valid email. Guard before sending so we never 422 the shopper on a partial order.
     */
    public function isComplete(): bool
    {
        return $this->givenName !== ''
            && $this->familyName !== ''
            && $this->email !== ''
            && $this->streetAndNumber !== ''
            && $this->postalCode !== ''
            && $this->city !== ''
            && $this->country !== '';
    }

    /**
     * @return array<string, string>
     */
    public function toMollieArray(): array
    {
        return [
            'givenName' => $this->givenName,
            'familyName' => $this->familyName,
            'email' => $this->email,
            'streetAndNumber' => $this->streetAndNumber,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'country' => strtoupper($this->country),
        ];
    }
}
