<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Exception;

use OxidEsales\GraphQL\Base\Exception\Error;
use OxidEsales\GraphQL\Base\Exception\ErrorCategories;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;

/**
 * A headless checkout refusal as the GraphQL client sees it: client-safe
 * (graphql-base `Error`), request-error category, the stable
 * `HeadlessCheckoutException::$errorCode` (and Mollie's own code when there is
 * one) in the extensions.
 *
 * @since 3.4.0
 */
final class MollieCheckoutError extends Error
{
    public function __construct(HeadlessCheckoutException $refusal)
    {
        parent::__construct(
            message: $refusal->getMessage(),
            previous: $refusal,
            category: ErrorCategories::REQUESTERROR,
            extensions: array_filter([
                'errorCode' => $refusal->errorCode,
                'providerCode' => $refusal->providerCode,
            ], static fn(?string $v): bool => $v !== null)
        );
    }

    public function getCategory(): string
    {
        return ErrorCategories::REQUESTERROR;
    }
}
