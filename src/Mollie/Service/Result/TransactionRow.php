<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service\Result;

use OxidEsales\Payments\Mollie\Adapter\MollieOutcome;

/**
 * View-model row for the admin Transaction History table. Normalises payment / capture / refund
 * rows fetched live from the Mollie API ({@see \OxidEsales\Payments\Mollie\Service\TransactionHistoryService})
 * into one shape the Twig panel renders without branching per row type.
 */
final readonly class TransactionRow
{
    public const TYPE_PAYMENT = 'PAYMENT';
    public const TYPE_CAPTURE = 'CAPTURE';
    public const TYPE_REFUND = 'REFUND';

    public function __construct(
        public string $type,
        public string $id,
        public float $amount,
        public string $currency,
        public string $status,
        public MollieOutcome $outcome,
        public ?string $createdAt = null,
    ) {
    }

    /**
     * Badge colour for the admin table: mirrors PayPal/Stripe's tri-state semantics, driven by
     * the provider-neutral {@see MollieOutcome} rather than raw Mollie status strings.
     */
    public function badgeTone(): string
    {
        return match ($this->outcome) {
            MollieOutcome::PAID, MollieOutcome::AUTHORIZED => 'success',
            MollieOutcome::PENDING => 'warning',
            MollieOutcome::CANCELED, MollieOutcome::EXPIRED, MollieOutcome::FAILED => 'danger',
            MollieOutcome::IGNORED => 'gray',
        };
    }
}
