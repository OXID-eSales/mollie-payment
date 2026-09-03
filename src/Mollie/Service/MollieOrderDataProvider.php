<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\BasketItem;
use OxidEsales\Eshop\Application\Model\Country;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Price;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAddressDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieProductLineInput;

/**
 * OXID implementation of {@see MollieOrderDataProviderInterface}. Reads the live session basket + user
 * (the reliable source during the checkout request — the early order is still a bare NOT_FINISHED
 * shell) and hands the raw lines to {@see MollieLinesBuilder}. Discounts and vouchers are passed as their own
 * line; only OXID's residual cent is left to the builder's fold, which is all that fold accepts.
 *
 * OXID access is isolated in protected seams (basket / basketUser / basketContents / countryIso) so
 * the mapping can be exercised without a shop bootstrap.
 */
class MollieOrderDataProvider implements MollieOrderDataProviderInterface
{
    public function billingAddress(): ?MollieAddressDto
    {
        $user = $this->basketUser();
        if ($user === null) {
            return null;
        }

        return new MollieAddressDto(
            $this->field($user, 'oxfname'),
            $this->field($user, 'oxlname'),
            $this->field($user, 'oxusername'),
            trim($this->field($user, 'oxstreet') . ' ' . $this->field($user, 'oxstreetnr')),
            $this->field($user, 'oxzip'),
            $this->field($user, 'oxcity'),
            $this->countryIso($this->field($user, 'oxcountryid')),
        );
    }

    public function lines(string $currency, float $expectedTotal): array
    {
        $basket = $this->basket();
        if ($basket === null) {
            return [];
        }

        $products = [];
        foreach ($this->basketContents($basket) as $item) {
            if ($item instanceof BasketItem) {
                $products[] = $this->productInput($item);
            }
        }

        [$shipping, $shippingVat] = $this->shipping($basket);

        return MollieLinesBuilder::build(
            $currency,
            $expectedTotal,
            $products,
            $shipping,
            $shippingVat,
            $this->discountGross($basket)
        );
    }

    /**
     * Basket-level discounts and vouchers, gross.
     *
     * This used to pass 0.0, on the assumption that MollieLinesBuilder's
     * residual fold would absorb the difference. It does not: the fold refuses
     * anything larger than a rounding ceiling, so a real voucher failed the
     * whole checkout with "line reconciliation is off by -10.65 ... the order
     * lines are incomplete". The lines genuinely WERE incomplete - the discount
     * belongs in them as its own line, not folded in as if it were rounding.
     *
     * Item-level discounts are already inside each item's unit price; these two
     * are the basket-level ones that are not, which is why summing them does
     * not double-count. Gross on purpose - the builder's parameter is gross.
     */
    protected function discountGross(Basket $basket): float
    {
        $discount = 0.0;

        $basketDiscount = $basket->getTotalDiscount();
        if ($basketDiscount instanceof Price) {
            $discount += (float) $basketDiscount->getBruttoPrice();
        }

        // Answers false, not 0.0, when no voucher is applied.
        $voucher = $basket->getVoucherDiscValue();
        if (is_numeric($voucher)) {
            $discount += (float) $voucher;
        }

        return $discount;
    }

    private function productInput(BasketItem $item): MollieProductLineInput
    {
        $unitPrice = $item->getUnitPrice();
        $title = (string) $item->getTitle();

        return new MollieProductLineInput(
            $title !== '' ? $title : 'Item',
            max(1, (int) round($item->getAmount())),
            (float) $unitPrice->getBruttoPrice(),
            (float) $unitPrice->getVat(),
        );
    }

    /**
     * @return array{0: float, 1: float} shipping gross + VAT rate
     */
    private function shipping(Basket $basket): array
    {
        $cost = $basket->getCosts('oxdelivery');
        if (!$cost instanceof Price) {
            return [0.0, 0.0];
        }

        return [(float) $cost->getBruttoPrice(), (float) $cost->getVat()];
    }

    protected function basket(): ?Basket
    {
        $basket = Registry::getSession()->getBasket();

        return $basket instanceof Basket ? $basket : null;
    }

    protected function basketUser(): ?User
    {
        $basket = $this->basket();
        $user = $basket?->getBasketUser();

        return $user instanceof User && $user->getId() ? $user : null;
    }

    /**
     * @return iterable<mixed>
     */
    protected function basketContents(Basket $basket): iterable
    {
        return $basket->getContents();
    }

    protected function countryIso(string $countryId): string
    {
        if ($countryId === '') {
            return '';
        }
        /** @var Country $country — oxNew model factory */
        $country = oxNew(Country::class);
        $country->load($countryId);

        return $this->field($country, 'oxisoalpha2');
    }

    protected function field(object $model, string $name): string
    {
        if (!method_exists($model, 'getFieldData')) {
            return '';
        }
        $value = $model->getFieldData($name);

        return is_scalar($value) ? (string) $value : '';
    }
}
