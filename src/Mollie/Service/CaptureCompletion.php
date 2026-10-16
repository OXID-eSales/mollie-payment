<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

/**
 * Whether an admin capture completes the order (MOL-32). {@see CaptureCompletionPolicy} decides;
 * {@see CaptureService} acts on it — fulfil on {@see self::FullyCaptured}, keep the contract
 * capturable on {@see self::PartialCaptureRemains}.
 */
enum CaptureCompletion
{
    /** Nothing left to capture (or a single-capture account, where the first capture is final). */
    case FullyCaptured;

    /** Multi-capture account with an authorized remainder still waiting — the order stays open. */
    case PartialCaptureRemains;
}
