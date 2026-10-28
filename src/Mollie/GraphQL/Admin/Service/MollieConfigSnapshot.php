<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * The Mollie settings as the admin API shows them. An API key is never part of it: only whether one is set.
 * `version()` is the optimistic-lock token of the settings (the module settings have no row timestamp); the
 * effective webhook URL is derived from the shop's URL and the override, so it is not part of it.
 */
final readonly class MollieConfigSnapshot
{
    public function __construct(
        public string $mode,
        public string $captureMode,
        public bool $multiCapture,
        public string $profileId,
        public string $webhookUrlOverride,
        public string $effectiveWebhookUrl,
        public string $logLevel,
        public bool $testKeySet,
        public bool $liveKeySet,
    ) {
    }

    public function keySet(string $mode): bool
    {
        return $mode === MollieDefinitions::MODE_LIVE ? $this->liveKeySet : $this->testKeySet;
    }

    public function version(): string
    {
        $fields = get_object_vars($this);
        unset($fields['effectiveWebhookUrl']);

        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }
}
