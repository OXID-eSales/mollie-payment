<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStepUpInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * `adminMollieConfigModify`: step-up (switching to live, or writing an API key), optimistic lock, validation, then
 * the write. The right is the controller's; one audit row per call.
 */
final class MollieConfigModifier implements MollieConfigModifierInterface
{
    private const OPERATION = 'adminMollieConfigModify';

    public function __construct(
        private readonly MollieAdminSettingsInterface $settings,
        private readonly MollieConfigChangeValidator $validator,
        private readonly AdminStepUpInterface $stepUp,
        private readonly MollieAdminAuditInterface $audit,
    ) {
    }

    public function modify(MollieConfigChange $change): MollieConfigResult
    {
        return $this->audit->audited(
            self::OPERATION,
            $change->fieldNames(),
            fn (): MollieConfigResult => $this->run($change)
        );
    }

    private function run(MollieConfigChange $change): MollieConfigResult
    {
        $current = $this->settings->snapshot();
        if ($this->needsStepUp($change, $current)) {
            $this->stepUp->require();
        }
        if ($change->expectedVersion !== $current->version()) {
            return MollieConfigResult::refused(
                MollieAdminRefusal::conflict($change->expectedVersion, $current->version())
            );
        }
        $refusal = $this->validator->validate($change) ?? $this->missingKeyRefusal($change, $current);
        if ($refusal !== null) {
            return MollieConfigResult::refused($refusal);
        }
        $this->settings->apply($change);

        return MollieConfigResult::done($this->settings->snapshot());
    }

    private function needsStepUp(MollieConfigChange $change, MollieConfigSnapshot $current): bool
    {
        return $this->switchesToLive($change, $current) || $change->writesKey();
    }

    private function switchesToLive(MollieConfigChange $change, MollieConfigSnapshot $current): bool
    {
        return $change->mode === MollieDefinitions::MODE_LIVE && $current->mode !== MollieDefinitions::MODE_LIVE;
    }

    /**
     * Switching the mode to one whose key is not there would make every payment fail: refused, nothing saved.
     */
    private function missingKeyRefusal(MollieConfigChange $change, MollieConfigSnapshot $current): ?MollieAdminRefusal
    {
        if ($change->mode === null || $change->mode === $current->mode) {
            return null;
        }
        $hasKey = $current->keySet($change->mode) || $change->keyFor($change->mode) !== null;

        return $hasKey
            ? null
            : MollieAdminRefusal::invalid(
                'mode',
                'key_missing',
                sprintf('The %s API key is needed for this mode', $change->mode)
            );
    }
}
