<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller\Webhook;

use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookGuardResult;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRequest;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRequestGuardInterface;

/**
 * A guard chain that lets everything through.
 *
 * Tests that exercise webhook *processing* used to pass `testGuard: null`, which — before Sprint 11
 * Story 5 (F3) — meant "no guard chain, carry on anyway". That is exactly the fail-open bug the
 * story removed, so those tests now have to state explicitly that the chain ran and passed.
 */
final class PassingWebhookGuard implements WebhookRequestGuardInterface
{
    public function check(WebhookRequest $request): WebhookGuardResult
    {
        return WebhookGuardResult::pass();
    }
}
