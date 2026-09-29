<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Eshop\Core\Exception\ArticleInputException;
use OxidEsales\Eshop\Core\Exception\OutOfStockException;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\Payments\Mollie\Service\BuyabilityFailure;
use OxidEsales\Payments\Mollie\Service\NotOrderableCheckoutFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * MOL-22 — recognises "an item is not orderable" in what the checkout-session dispatch throws:
 * payment-base wraps core's ArticleException family into a ShopOrderException with code
 * `article_not_buyable` and keeps the core exception as `previous`.
 */
#[CoversClass(NotOrderableCheckoutFailure::class)]
final class NotOrderableCheckoutFailureTest extends TestCase
{
    public function testCarriesTheTitlesOfThePreDispatchFailures(): void
    {
        $failure = NotOrderableCheckoutFailure::fromBuyabilityFailures([
            new BuyabilityFailure('art-1', 'Ocean Eyes'),
            new BuyabilityFailure('art-3', 'Kite'),
        ]);

        self::assertSame(['Ocean Eyes', 'Kite'], $failure->productTitles());
        self::assertNull($failure->cause());
    }

    public function testRecognisesPaymentBasesArticleNotBuyableCodeAndKeepsTheCoreCause(): void
    {
        $core = new OutOfStockException('ERROR_MESSAGE_OUTOFSTOCK_OUTOFSTOCK');
        $wrapped = new ShopOrderException(
            message: 'ERROR_MESSAGE_OUTOFSTOCK_OUTOFSTOCK',
            errorCode: 'article_not_buyable',
            context: [],
            previous: $core
        );

        $failure = NotOrderableCheckoutFailure::fromThrowable(new RuntimeException('dispatch failed', 0, $wrapped));

        self::assertNotNull($failure);
        self::assertSame($core, $failure->cause());
        self::assertSame([], $failure->productTitles());
    }

    public function testRecognisesTheCodeEvenWithoutACoreCause(): void
    {
        $wrapped = new ShopOrderException(message: 'gone', errorCode: 'article_not_buyable', context: []);

        $failure = NotOrderableCheckoutFailure::fromThrowable($wrapped);

        self::assertNotNull($failure);
        self::assertNull($failure->cause());
    }

    public function testRecognisesABareCoreArticleException(): void
    {
        $core = new ArticleInputException('ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE');

        self::assertSame($core, NotOrderableCheckoutFailure::fromThrowable($core)?->cause());
    }

    public function testAnswersNullForAnyOtherFailure(): void
    {
        $other = new ShopOrderException(message: 'state 7', errorCode: 'invalid_delivery_address', context: []);

        self::assertNull(NotOrderableCheckoutFailure::fromThrowable($other));
        self::assertNull(NotOrderableCheckoutFailure::fromThrowable(new RuntimeException('boom')));
    }
}
