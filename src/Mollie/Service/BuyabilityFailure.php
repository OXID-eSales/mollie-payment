<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * One basket item that is not orderable right now: the article id and the title the
 * shopper saw in the basket, so the message can name it (MOL-22).
 */
final class BuyabilityFailure
{
    public function __construct(
        public readonly string $articleId,
        public readonly string $productTitle,
    ) {
    }
}
