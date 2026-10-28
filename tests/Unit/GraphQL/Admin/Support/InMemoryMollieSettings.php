<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieAdminSettingsInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigChange;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigSnapshot;

final class InMemoryMollieSettings implements MollieAdminSettingsInterface
{
    public int $writes = 0;

    /** @var array<string, string> mode => stored key */
    public array $keys;

    public function __construct(private MollieConfigSnapshot $current)
    {
        $this->keys = ['test' => $current->testKeySet ? ConfigFixtures::TEST_KEY : '', 'live' => ''];
    }

    public function snapshot(): MollieConfigSnapshot
    {
        return $this->current;
    }

    public function apply(MollieConfigChange $change): void
    {
        ++$this->writes;
        foreach (['test', 'live'] as $mode) {
            $key = $change->keyFor($mode);
            if ($key !== null) {
                $this->keys[$mode] = $key;
            }
        }
        $this->current = new MollieConfigSnapshot(
            mode: $change->mode ?? $this->current->mode,
            captureMode: $change->captureMode ?? $this->current->captureMode,
            multiCapture: $change->multiCapture ?? $this->current->multiCapture,
            profileId: $change->profileId ?? $this->current->profileId,
            webhookUrlOverride: $change->webhookUrlOverride ?? $this->current->webhookUrlOverride,
            effectiveWebhookUrl: $this->current->effectiveWebhookUrl,
            logLevel: $change->logLevel ?? $this->current->logLevel,
            testKeySet: ($this->keys['test'] ?? '') !== '',
            liveKeySet: ($this->keys['live'] ?? '') !== '',
        );
    }
}
