<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\Country;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAddressDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieProductLineInput;

/**
 * OXID implementation of {@see MollieOrderDataProviderInterface}. Reads the order that
 * EarlyOrderCreationHandler already created (NOT_FINISHED) — the authoritative, VAT-resolved source —
 * and hands the raw lines to {@see MollieLinesBuilder}, whose residual-fold absorbs any OXID rounding
 * so the Mollie lines always reconcile to the payment total.
 *
 * OXID-model access is isolated in protected seams (loadOrder / orderArticles / field / countryIso) so
 * the mapping can be exercised without a shop bootstrap.
 */
class MollieOrderDataProvider implements MollieOrderDataProviderInterface
{
    public function billingAddress(string $orderId): ?MollieAddressDto
    {
        $order = $this->loadOrder($orderId);
        if ($order === null) {
            return null;
        }

        return new MollieAddressDto(
            $this->field($order, 'oxbillfname'),
            $this->field($order, 'oxbilllname'),
            $this->field($order, 'oxbillemail'),
            trim($this->field($order, 'oxbillstreet') . ' ' . $this->field($order, 'oxbillstreetnr')),
            $this->field($order, 'oxbillzip'),
            $this->field($order, 'oxbillcity'),
            $this->countryIso($this->field($order, 'oxbillcountryid')),
        );
    }

    public function lines(string $orderId, string $currency, float $expectedTotal): array
    {
        $order = $this->loadOrder($orderId);
        if ($order === null) {
            return [];
        }

        $products = [];
        foreach ($this->orderArticles($order) as $article) {
            if (!is_object($article)) {
                continue;
            }
            $products[] = new MollieProductLineInput(
                $this->field($article, 'oxtitle'),
                max(1, (int) round((float) $this->field($article, 'oxamount'))),
                (float) $this->field($article, 'oxbprice'),
                (float) $this->field($article, 'oxvat'),
            );
        }

        $shipping = (float) $this->field($order, 'oxdelcost');
        $shippingVat = (float) $this->field($order, 'oxdelvat');
        $discount = (float) $this->field($order, 'oxdiscount') + (float) $this->field($order, 'oxvoucherdiscount');

        return MollieLinesBuilder::build($currency, $expectedTotal, $products, $shipping, $shippingVat, $discount);
    }

    protected function loadOrder(string $orderId): ?Order
    {
        if ($orderId === '') {
            return null;
        }
        /** @var Order $order — oxNew model factory */
        $order = oxNew(Order::class);

        return $order->load($orderId) ? $order : null;
    }

    /**
     * @return iterable<mixed>
     */
    protected function orderArticles(Order $order): iterable
    {
        $articles = $order->getOrderArticles();

        return is_iterable($articles) ? $articles : [];
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
