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
 * shell) and hands the raw lines to {@see MollieLinesBuilder}, whose residual-fold absorbs discounts
 * and OXID rounding so the Mollie lines always reconcile to the payment total.
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

        // Discounts/vouchers are absorbed by MollieLinesBuilder's residual fold (products+shipping
        // exceed the discounted total), so no explicit discount line is read here.
        return MollieLinesBuilder::build($currency, $expectedTotal, $products, $shipping, $shippingVat, 0.0);
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
