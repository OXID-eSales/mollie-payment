<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookResult;

/**
 * Value object returned by each {@see MollieWebhookEventHandlerInterface}.
 *
 * Bundles the provider-agnostic WebhookResult with the contract ID resolved during handler
 * execution. payment-base's WebhookResult carries no contractId; this VO adds that field
 * without modifying the shared package, so {@see MollieWebhookProcessor::getContractIdFromResult()}
 * can link the webhook log row to the correct contract.
 */
readonly class MollieWebhookOutcome
{
    public function __construct(
        public WebhookResult $result,
        public ?string $contractId = null,
    ) {
    }

    public static function of(WebhookResult $result, ?string $contractId = null): self
    {
        return new self($result, $contractId);
    }
}
