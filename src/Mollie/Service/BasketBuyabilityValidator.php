<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\BasketItem;

/**
 * Answers "which items in this basket are not orderable right now?" (MOL-22).
 *
 * Asked right before the checkout-session event is dispatched - on the standard order step and
 * in the OPC payment handler - so an item that turned unbuyable after the page rendered is
 * reported to the shopper before any contract or draft order exists. Core's finalizeOrder()
 * remains the safety net for the race window (payment-base maps its refusal to the
 * `article_not_buyable` code, see {@see NotOrderableCheckoutFailure::fromThrowable()}).
 */
class BasketBuyabilityValidator
{
    /**
     * @return list<BuyabilityFailure>
     */
    public function validate(Basket $basket): array
    {
        $failures = [];
        foreach ($basket->getContents() as $item) {
            if (!$item instanceof BasketItem) {
                continue;
            }
            $failure = $this->inspect($item);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    private function inspect(BasketItem $item): ?BuyabilityFailure
    {
        $article = $item->getArticle();
        if (!$article instanceof Article || $article->isBuyable()) {
            return null;
        }

        return new BuyabilityFailure((string) $article->getId(), (string) $item->getTitle());
    }
}
