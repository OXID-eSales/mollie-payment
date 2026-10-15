<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * What {@see CheckoutSessionStarter::start()} decided. The entry points translate it into their
 * medium: the controller redirects / fills error slots, the OPC handler builds a
 * PaymentHandlerResult. Exactly one of the factory shapes applies:
 *
 *  - notOrderable      — MOL-22, an item cannot be ordered; {@see $notOrderable} names it
 *  - userDataProblems  — MOL-15, address data must be fixed first; translated messages
 *  - replay            — MOL-18, an attempt is already in flight; {@see $checkoutUrl} rejoins it
 *  - ready             — fresh Mollie checkout session; {@see $checkoutUrl} + {@see $contractId}
 *  - unavailable       — no checkout possible; {@see $contractId} when a contract already exists
 */
final readonly class CheckoutStartOutcome
{
    /**
     * @param list<string> $userDataProblems
     */
    private function __construct(
        public ?NotOrderableCheckoutFailure $notOrderable = null,
        public array $userDataProblems = [],
        public ?string $checkoutUrl = null,
        public string $contractId = '',
        private bool $unavailable = false,
    ) {
    }

    public static function notOrderable(NotOrderableCheckoutFailure $failure): self
    {
        return new self(notOrderable: $failure);
    }

    /**
     * @param non-empty-list<string> $problems
     */
    public static function userDataProblems(array $problems): self
    {
        return new self(userDataProblems: $problems);
    }

    public static function replay(string $checkoutUrl): self
    {
        return new self(checkoutUrl: $checkoutUrl);
    }

    public static function ready(string $checkoutUrl, string $contractId): self
    {
        return new self(checkoutUrl: $checkoutUrl, contractId: $contractId);
    }

    public static function unavailable(string $contractId = ''): self
    {
        return new self(contractId: $contractId, unavailable: true);
    }

    public function isNotOrderable(): bool
    {
        return $this->notOrderable !== null;
    }

    public function hasUserDataProblems(): bool
    {
        return $this->userDataProblems !== [];
    }

    /**
     * Replay and ready alike: the shopper's next stop is Mollie.
     */
    public function isRedirect(): bool
    {
        return $this->checkoutUrl !== null && $this->checkoutUrl !== '';
    }

    public function isUnavailable(): bool
    {
        return $this->unavailable;
    }
}
