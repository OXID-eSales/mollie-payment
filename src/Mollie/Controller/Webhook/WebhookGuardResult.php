<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Webhook;

final readonly class WebhookGuardResult
{
    public function __construct(
        public bool $ok,
        public int $httpStatus = 200,
        public ?string $reason = null,
    ) {
    }

    public static function pass(): self
    {
        return new self(true);
    }

    public static function reject(int $httpStatus, string $reason): self
    {
        return new self(false, $httpStatus, $reason);
    }
}
