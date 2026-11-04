<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Core;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use Throwable;

/**
 * What a log line says about a Throwable, for the classes the shop instantiates itself (controllers, the view config,
 * module events, widgets) and therefore cannot constructor-inject a {@see ThrowableSummaryInterface} into. Everything
 * else takes the interface through the container.
 *
 * The summary comes from payment-base's service, so the extractors of the providers (Mollie's API failure facts) apply.
 * When the container cannot hand it out - the very thing some of these log lines report - the plain class-and-code
 * summary is used: it is the same safe shape with fewer facts, never the message.
 */
final class LoggableThrowable
{
    /**
     * @return array<string, scalar|null>
     */
    public static function summary(Throwable $throwable): array
    {
        return self::summarizer()->fromThrowable($throwable);
    }

    private static function summarizer(): ThrowableSummaryInterface
    {
        try {
            $summarizer = ContainerFactory::getInstance()->getContainer()->get(ThrowableSummaryInterface::class);
        } catch (Throwable) {
            return new ThrowableSummary([]);
        }

        return $summarizer instanceof ThrowableSummaryInterface ? $summarizer : new ThrowableSummary([]);
    }
}
