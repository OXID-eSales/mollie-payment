<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * MOL-32 — decides whether an admin capture completes the order, abstracting the single-vs-multi
 * capture behaviour so {@see CaptureService} depends on the decision, not on the account setting.
 */
interface CaptureCompletionPolicyInterface
{
    /**
     * @param float $remainingToCapture how much of the authorization is still uncaptured after this
     *                                  capture (major units)
     */
    public function classify(float $remainingToCapture): CaptureCompletion;
}
