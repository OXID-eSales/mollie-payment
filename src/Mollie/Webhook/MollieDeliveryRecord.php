<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookDeliveryLogRecord;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Builds the file-log line of one Mollie webhook delivery (payment-base's {@see WebhookDeliveryLogRecord}): ids and a
 * machine-readable reason, never payload content. `$startedAtNs` is the `hrtime(true)` taken when the request began.
 */
final class MollieDeliveryRecord
{
    /**
     * A delivery the endpoint turned away before the processor saw it (guard refusal, missing id, no processor).
     */
    public static function refused(
        int $startedAtNs,
        ?string $paymentId,
        string $action,
        string $reason,
        int $httpCode,
    ): WebhookDeliveryLogRecord {
        return new WebhookDeliveryLogRecord(
            MollieDefinitions::PROVIDER_NAME,
            null,
            null,
            $paymentId,
            null,
            $action,
            $reason,
            $httpCode,
            self::elapsedMs($startedAtNs),
        );
    }

    public static function processed(
        int $startedAtNs,
        string $paymentId,
        ?string $contractId,
        WebhookResult $result,
        int $httpCode,
    ): WebhookDeliveryLogRecord {
        return WebhookDeliveryLogRecord::fromResult(
            MollieDefinitions::PROVIDER_NAME,
            null,
            null,
            $paymentId,
            $contractId,
            $result,
            $httpCode,
            self::elapsedMs($startedAtNs),
        );
    }

    private static function elapsedMs(int $startedAtNs): int
    {
        return (int) ((hrtime(true) - $startedAtNs) / 1_000_000);
    }
}
