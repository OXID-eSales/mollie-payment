<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * The one place the single-vs-multi-capture decision is made (MOL-32 S2).
 *
 * The account capability is read from {@see ModuleConfigurationServiceInterface::isMultiCaptureEnabled()}
 * here and nowhere else, so the services that act on captures ({@see CaptureService} and the admin
 * panel) depend on this policy, not on the setting — a future "auto-detect from Mollie" rule becomes
 * a new implementation of {@see CaptureCompletionPolicyInterface}, not edits spread across them.
 *
 * - Single-capture account (setting off, today's default): the first capture is always final —
 *   Mollie releases any remainder (MOL-31 option B). The order completes.
 * - Multi-capture account (setting on, confirmed with Mollie): the order completes only once the
 *   whole authorization is captured; a remainder keeps it open for the next capture.
 */
final class CaptureCompletionPolicy implements CaptureCompletionPolicyInterface
{
    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config,
    ) {
    }

    public function classify(float $remainingToCapture): CaptureCompletion
    {
        if (!$this->config->isMultiCaptureEnabled()) {
            return CaptureCompletion::FullyCaptured;
        }

        // A remainder above float-representation noise keeps the order open; a cent or more is real.
        return MoneyComparison::exceeds($remainingToCapture, 0.0)
            ? CaptureCompletion::PartialCaptureRemains
            : CaptureCompletion::FullyCaptured;
    }
}
