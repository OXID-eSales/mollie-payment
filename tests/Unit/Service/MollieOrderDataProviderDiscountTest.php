<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\BasketItem;
use OxidEsales\Eshop\Core\Price;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieLineDto;
use OxidEsales\Payments\Mollie\Service\MollieOrderDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A basket carrying a voucher used to fail the whole Mollie checkout:
 *
 *   Mollie line reconciliation is off by -10.65 against an expected total of
 *   105.85, which is too large to be rounding (ceiling 1.06). The order lines
 *   are incomplete - refusing to fold the difference into a single "Rounding
 *   adjustment" line.
 *
 * The guard was right: the provider passed 0.0 as the discount, so the lines
 * really were incomplete. The discount belongs in the lines as its own line.
 */
#[CoversClass(MollieOrderDataProvider::class)]
final class MollieOrderDataProviderDiscountTest extends TestCase
{
    /** The basket from the report: 106.50 of product, 10.00 shipping, 10.65 voucher. */
    private const PRODUCT_GROSS = 106.50;
    private const SHIPPING_GROSS = 10.00;
    private const VOUCHER = 10.65;
    private const EXPECTED_TOTAL = 105.85;

    private function price(float $gross, float $vat): Price
    {
        $price = $this->createMock(Price::class);
        $price->method('getBruttoPrice')->willReturn($gross);
        $price->method('getVat')->willReturn($vat);

        return $price;
    }

    private function basket(float|bool $voucher, ?Price $basketDiscount): Basket
    {
        $basket = $this->createMock(Basket::class);
        $basket->method('getCosts')->willReturn($this->price(self::SHIPPING_GROSS, 19.0));
        $basket->method('getVoucherDiscValue')->willReturn($voucher);
        $basket->method('getTotalDiscount')->willReturn($basketDiscount);

        return $basket;
    }

    private function item(): BasketItem
    {
        $item = $this->createMock(BasketItem::class);
        $item->method('getUnitPrice')->willReturn($this->price(self::PRODUCT_GROSS, 19.0));
        $item->method('getAmount')->willReturn(1.0);
        $item->method('getTitle')->willReturn('Test product');

        return $item;
    }

    private function provider(Basket $basket): MollieOrderDataProvider
    {
        $item = $this->item();

        return new class ($basket, $item) extends MollieOrderDataProvider {
            public function __construct(private readonly Basket $stubBasket, private readonly BasketItem $stubItem)
            {
            }

            protected function basket(): ?Basket
            {
                return $this->stubBasket;
            }

            protected function basketContents(Basket $basket): iterable
            {
                return [$this->stubItem];
            }
        };
    }

    /** @param array<int, MollieLineDto> $lines */
    private function sum(array $lines): float
    {
        return round(array_sum(array_map(static fn(MollieLineDto $l): float => $l->totalAmount->value, $lines)), 2);
    }

    public function testAVoucherBecomesItsOwnDiscountLine(): void
    {
        $lines = $this->provider($this->basket(self::VOUCHER, null))
            ->lines('EUR', self::EXPECTED_TOTAL);

        $discounts = array_values(array_filter(
            $lines,
            static fn(MollieLineDto $l): bool => $l->type === MollieLineDto::TYPE_DISCOUNT
        ));

        self::assertCount(1, $discounts, 'the voucher must appear as a discount line');
        self::assertSame(-self::VOUCHER, $discounts[0]->totalAmount->value);
    }

    public function testTheLinesReconcileToTheAmountMollieWillCharge(): void
    {
        // This is the assertion that would have caught the bug: before the fix
        // the builder threw here rather than returning lines at all.
        $lines = $this->provider($this->basket(self::VOUCHER, null))
            ->lines('EUR', self::EXPECTED_TOTAL);

        self::assertSame(self::EXPECTED_TOTAL, $this->sum($lines));
    }

    public function testABasketDiscountAndAVoucherAreBothCounted(): void
    {
        // Neither is inside the item unit price, so both have to be passed.
        $lines = $this->provider($this->basket(self::VOUCHER, $this->price(5.00, 0.0)))
            ->lines('EUR', self::EXPECTED_TOTAL - 5.00);

        self::assertSame(self::EXPECTED_TOTAL - 5.00, $this->sum($lines));
    }

    public function testABasketWithoutAVoucherIsUnaffected(): void
    {
        // getVoucherDiscValue() answers false, not 0.0, when nothing is applied.
        $lines = $this->provider($this->basket(false, null))
            ->lines('EUR', self::PRODUCT_GROSS + self::SHIPPING_GROSS);

        self::assertSame(self::PRODUCT_GROSS + self::SHIPPING_GROSS, $this->sum($lines));
        self::assertSame(
            [],
            array_filter($lines, static fn(MollieLineDto $l): bool => $l->type === MollieLineDto::TYPE_DISCOUNT)
        );
    }
}
