<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use Mollie\Api\Exceptions\ApiException;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryExtractorInterface;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use Throwable;

/**
 * What a log line may say about a Mollie API failure: the HTTP status and the NAME of the offending field. Never
 * `getMessage()`: the SDK's text embeds the request body (card token `tkn_...`, addresses, e-mail).
 *
 * Handles the SDK exception itself and the module's {@see MollieAdapterException}, which carries the same facts.
 * Tagged `payment_base.throwable_summary`, so every {@see \OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface}
 * caller gets them.
 */
final class MollieApiExceptionSummary implements ThrowableSummaryExtractorInterface
{
    public function supports(Throwable $throwable): bool
    {
        return $throwable instanceof ApiException || $throwable instanceof MollieAdapterException;
    }

    public function summarize(Throwable $throwable): array
    {
        if ($throwable instanceof MollieAdapterException) {
            return [
                'httpStatus' => $throwable->getCode(),
                'field' => $throwable->field,
            ];
        }

        if (!$throwable instanceof ApiException) {
            return [];
        }

        return [
            'httpStatus' => $throwable->getCode(),
            'field' => $throwable->getField(),
        ];
    }
}
