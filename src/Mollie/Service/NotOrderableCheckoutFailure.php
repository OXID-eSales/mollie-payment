<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\Eshop\Core\Exception\ArticleException;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use Throwable;

/**
 * "The order cannot be completed because an item is not orderable" - as one fact the two
 * checkouts can present (MOL-22).
 *
 * Built either from the pre-dispatch buyability check (items known by title) or from what the
 * checkout-session dispatch threw: payment-base's OxidShopOrderService wraps core's
 * ArticleException family (turned unbuyable, out of stock for the ordered quantity, removed) into
 * a ShopOrderException with code `article_not_buyable` and keeps the core exception as `previous`.
 */
final class NotOrderableCheckoutFailure
{
    /** payment-base's error code for a core ArticleException raised inside finalizeOrder(). */
    public const PAYMENT_BASE_CODE = 'article_not_buyable';

    /**
     * @param list<string> $productTitles
     */
    private function __construct(
        private readonly array $productTitles,
        private readonly ?ArticleException $cause,
    ) {
    }

    /**
     * @param list<BuyabilityFailure> $failures
     */
    public static function fromBuyabilityFailures(array $failures): self
    {
        return new self(
            array_values(array_map(
                static fn (BuyabilityFailure $failure): string => $failure->productTitle,
                $failures
            )),
            null
        );
    }

    /**
     * Null when the failure is about something else (payment, address, session, ...).
     */
    public static function fromThrowable(Throwable $throwable): ?self
    {
        $flagged = false;
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ArticleException) {
                return new self([], $current);
            }
            if ($current instanceof ShopOrderException && $current->getErrorCode() === self::PAYMENT_BASE_CODE) {
                $flagged = true;
            }
        }

        return $flagged ? new self([], null) : null;
    }

    /**
     * Titles of the items known to be not orderable (empty when only core reported the refusal).
     *
     * @return list<string>
     */
    public function productTitles(): array
    {
        return $this->productTitles;
    }

    /**
     * Core's own exception, when it was the one that refused - it carries the shop's own wording
     * (e.g. the remaining stock) and is shown next to the module's sentences.
     */
    public function cause(): ?ArticleException
    {
        return $this->cause;
    }
}
