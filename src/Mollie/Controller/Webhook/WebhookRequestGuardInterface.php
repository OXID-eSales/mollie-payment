<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Webhook;

/**
 * Guard interface for webhook request validation.
 *
 * Returns a passing or rejecting {@see WebhookGuardResult}. Guards are composed via
 * {@see WebhookGuardChain} (Chain of Responsibility pattern). ISP: single method.
 */
interface WebhookRequestGuardInterface
{
    public function check(WebhookRequest $request): WebhookGuardResult;
}
