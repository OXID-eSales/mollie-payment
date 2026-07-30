<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieLineDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieProductLineInput;
use OxidEsales\Payments\Mollie\Service\MollieLinesBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieLinesBuilder::class)]
final class MollieLinesBuilderTest extends TestCase
{
    /** @return list<MollieLineDto> */
    private function build(float $total, array $products, float $shipping = 0.0, float $shipVat = 19.0, float $discount = 0.0): array
    {
        return MollieLinesBuilder::build('eur', $total, $products, $shipping, $shipVat, $discount);
    }

    private static function sum(array $lines): float
    {
        return round(array_sum(array_map(static fn (MollieLineDto $l): float => $l->totalAmount->value, $lines)), 2);
    }

    public function testSingleProduct_LineAmountsAndInclusiveVat(): void
    {
        $lines = $this->build(10.00, [new MollieProductLineInput('Item', 1, 10.00, 19.0)]);

        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertSame('EUR', $line->totalAmount->currency);
        self::assertSame(10.00, $line->totalAmount->value);
        self::assertSame(10.00, $line->unitPrice->value);
        // Inclusive VAT: 10 * 19/119 = 1.60.
        self::assertSame(1.60, $line->vatAmount->value);
        self::assertSame(MollieLineDto::TYPE_PHYSICAL, $line->type);
    }

    public function testUnitPriceTimesQuantityEqualsTotalPerLine(): void
    {
        $lines = $this->build(411.00, [new MollieProductLineInput('Wishbone', 3, 137.00, 19.0)]);

        $line = $lines[0];
        self::assertSame(round($line->unitPrice->value * $line->quantity, 2), $line->totalAmount->value);
        self::assertSame(411.00, $line->totalAmount->value);
    }

    public function testProductsPlusShipping_SumEqualsTotal(): void
    {
        $lines = $this->build(115.04, [new MollieProductLineInput('Item', 1, 10.00, 19.0)], shipping: 105.04);

        self::assertSame(115.04, self::sum($lines));
        self::assertSame(MollieLineDto::TYPE_SHIPPING_FEE, end($lines)->type);
    }

    public function testDiscountIsAppliedAsNegativeLine(): void
    {
        $lines = $this->build(8.00, [new MollieProductLineInput('Item', 1, 10.00, 19.0)], discount: 2.00);

        self::assertSame(8.00, self::sum($lines));
        $discount = array_values(array_filter($lines, static fn (MollieLineDto $l): bool => $l->type === MollieLineDto::TYPE_DISCOUNT))[0];
        self::assertSame(-2.00, $discount->totalAmount->value);
        self::assertSame(0.0, $discount->vatAmount->value);
    }

    public function testRoundingResidualIsFoldedOntoAnAdjustmentLine(): void
    {
        // Product lines sum to 10.02 but the order total is 10.00 → a -0.02 discount adjustment.
        $lines = $this->build(10.00, [
            new MollieProductLineInput('A', 3, 3.34, 19.0), // 3 * 3.34 = 10.02
        ]);

        self::assertSame(10.00, self::sum($lines));
        $last = end($lines);
        self::assertSame(MollieLineDto::TYPE_DISCOUNT, $last->type);
        self::assertSame(-0.02, $last->totalAmount->value);
    }

    public function testPositiveResidualBecomesASurcharge(): void
    {
        // 3 * 3.33 = 9.99, total 10.00 → +0.01 surcharge.
        $lines = $this->build(10.00, [new MollieProductLineInput('A', 3, 3.33, 19.0)]);

        self::assertSame(10.00, self::sum($lines));
        self::assertSame(MollieLineDto::TYPE_SURCHARGE, end($lines)->type);
        self::assertSame(0.01, end($lines)->totalAmount->value);
    }

    public function testNoAdjustmentLineWhenAlreadyBalanced(): void
    {
        $lines = $this->build(20.00, [new MollieProductLineInput('A', 2, 10.00, 19.0)]);

        self::assertCount(1, $lines);
        self::assertSame(20.00, self::sum($lines));
    }

    public function testZeroVatProduct_HasZeroVatAmount(): void
    {
        $lines = $this->build(10.00, [new MollieProductLineInput('Book', 1, 10.00, 0.0)]);

        self::assertSame(0.0, $lines[0]->vatAmount->value);
    }

    /**
     * @param list<MollieProductLineInput> $products
     */
    #[DataProvider('mixedBaskets')]
    public function testSumAlwaysEqualsOrderTotal(float $total, array $products, float $shipping, float $discount): void
    {
        $lines = $this->build($total, $products, $shipping, 19.0, $discount);

        self::assertSame($total, self::sum($lines), 'sum(lines) must equal the order total to the cent');
        foreach ($lines as $line) {
            self::assertSame(
                round($line->unitPrice->value * $line->quantity, 2),
                $line->totalAmount->value,
                'unitPrice*quantity must equal totalAmount per line',
            );
        }
    }

    /**
     * @return array<string, array{float, list<MollieProductLineInput>, float, float}>
     */
    public static function mixedBaskets(): array
    {
        return [
            'plain' => [30.00, [new MollieProductLineInput('A', 2, 15.00, 19.0)], 0.0, 0.0],
            'shipping+discount' => [
                113.45,
                [new MollieProductLineInput('A', 1, 10.00, 19.0), new MollieProductLineInput('B', 1, 3.41, 7.0)],
                105.04,
                5.00,
            ],
            'awkward-rounding' => [100.00, [new MollieProductLineInput('A', 7, 14.2857, 19.0)], 0.0, 0.0],
            'mixed-vat+ship' => [
                256.45,
                [new MollieProductLineInput('A', 3, 42.00, 19.0), new MollieProductLineInput('B', 5, 5.00, 7.0)],
                105.45,
                0.0,
            ],
        ];
    }
}
