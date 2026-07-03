<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller\Webhook;

use RuntimeException;

/**
 * Thrown by {@see TestableWebhookController::sendResponse()} to satisfy the `never` return-type
 * contract while letting the test inspect what would have been sent.
 */
final class WebhookResponseSent extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $action,
    ) {
        parent::__construct("HTTP {$statusCode}: {$action}");
    }
}
