<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter\Dto;

/**
 * One Mollie order line. Mollie's invariants (all checked to the cent):
 *   unitPrice.value * quantity == totalAmount.value
 *   vatAmount.value == totalAmount.value * (vatRate / (100 + vatRate))   (inclusive VAT)
 *   sum(totalAmount) over all lines == payment.amount
 * {@see \OxidEsales\Payments\Mollie\Service\MollieLinesBuilder} is the only place these are enforced.
 */
final readonly class MollieLineDto
{
    // Mollie line types.
    public const TYPE_PHYSICAL = 'physical';
    public const TYPE_SHIPPING_FEE = 'shipping_fee';
    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_SURCHARGE = 'surcharge';

    public function __construct(
        public string $description,
        public int $quantity,
        public MollieAmountDto $unitPrice,
        public MollieAmountDto $totalAmount,
        public float $vatRate,
        public MollieAmountDto $vatAmount,
        public string $type = self::TYPE_PHYSICAL,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toMollieArray(): array
    {
        return [
            'type' => $this->type,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice->toMollieArray(),
            'totalAmount' => $this->totalAmount->toMollieArray(),
            'vatRate' => number_format($this->vatRate, 2, '.', ''),
            'vatAmount' => $this->vatAmount->toMollieArray(),
        ];
    }
}
