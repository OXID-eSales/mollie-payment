<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;

/**
 * Everything a checkout start needs, gathered by the entry point that owns the medium:
 * MollieOrderController reads the PHP session + order form, MolliePaymentHandler reads OPC's
 * PaymentContext (and, headless, payment-base's metadata bag). {@see CheckoutSessionStarter}
 * consumes it — one ladder, however the shopper arrived (review 2026-10-14, A-H1).
 */
final readonly class CheckoutStartRequest
{
    public function __construct(
        public ?User $user,
        public ?Basket $basket,
        public string $sessionId,
        public ?string $selectedMethod = null,
        public ?string $cardToken = null,
        public bool $headless = false,
        public ?string $basketId = null,
        public ?string $returnUrl = null,
    ) {
    }
}
