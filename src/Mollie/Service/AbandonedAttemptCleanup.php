<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Checkout\PreviousCheckoutAttemptCleanerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * STRP-171 — retires the checkout attempt of a shopper who came back from
 * Mollie without paying and did not retry.
 *
 * The order is created before the shopper leaves for Mollie, so without this
 * it sits NOT_FINISHED in the backend until the age-based sweep runs, which
 * can be days.
 *
 * It is a service rather than a method on MollieOrderController because that
 * controller is already at the edge of its complexity budget, and this has
 * nothing to do with rendering a return.
 */
final class AbandonedAttemptCleanup
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly PreviousCheckoutAttemptCleanerInterface $cleaner,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Best-effort: the cleaner refuses to touch a committed contract, and a
     * failure here must not change what the shopper sees.
     */
    public function retire(string $contractId): void
    {
        try {
            $this->cleaner->clean($contractId);
        } catch (Throwable $e) {
            $this->logger->error('Could not retire the abandoned checkout attempt', [
                'contract_id' => $contractId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
