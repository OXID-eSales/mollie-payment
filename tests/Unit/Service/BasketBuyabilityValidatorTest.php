<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\BasketItem;
use OxidEsales\Payments\Mollie\Service\BasketBuyabilityValidator;
use OxidEsales\Payments\Mollie\Service\BuyabilityFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * MOL-22 — "which items in this basket are not orderable right now?", asked before any contract or
 * order is created.
 */
#[CoversClass(BasketBuyabilityValidator::class)]
#[CoversClass(BuyabilityFailure::class)]
final class BasketBuyabilityValidatorTest extends TestCase
{
    public function testReportsEveryItemWhoseArticleIsNotBuyableWithIdAndTitle(): void
    {
        $basket = $this->basketWith([
            $this->item('art-1', 'Ocean Eyes', buyable: false),
            $this->item('art-2', 'Roadurance', buyable: true),
            $this->item('art-3', 'Kite', buyable: false),
        ]);

        $failures = (new BasketBuyabilityValidator())->validate($basket);

        self::assertCount(2, $failures);
        self::assertSame('art-1', $failures[0]->articleId);
        self::assertSame('Ocean Eyes', $failures[0]->productTitle);
        self::assertSame('art-3', $failures[1]->articleId);
        self::assertSame('Kite', $failures[1]->productTitle);
    }

    public function testAnswersNothingForABasketOfBuyableItems(): void
    {
        $basket = $this->basketWith([$this->item('art-2', 'Roadurance', buyable: true)]);

        self::assertSame([], (new BasketBuyabilityValidator())->validate($basket));
    }

    public function testSkipsContentsThatAreNotBasketItemsOrCarryNoArticle(): void
    {
        $orphan = $this->createMock(BasketItem::class);
        $orphan->method('getArticle')->willReturn(null);
        $basket = $this->basketWith(['not an item', $orphan]);

        self::assertSame([], (new BasketBuyabilityValidator())->validate($basket));
    }

    /** @param list<mixed> $contents */
    private function basketWith(array $contents): Basket
    {
        $basket = $this->createMock(Basket::class);
        $basket->method('getContents')->willReturn($contents);

        return $basket;
    }

    private function item(string $articleId, string $title, bool $buyable): BasketItem
    {
        $article = $this->createMock(Article::class);
        $article->method('isBuyable')->willReturn($buyable);
        $article->method('getId')->willReturn($articleId);
        $item = $this->createMock(BasketItem::class);
        $item->method('getArticle')->willReturn($article);
        $item->method('getTitle')->willReturn($title);

        return $item;
    }
}
