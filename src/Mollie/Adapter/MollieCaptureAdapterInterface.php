<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use OxidEsales\Payments\Mollie\Adapter\Dto\CaptureRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;

/**
 * Capture operations (ISP slice 2 of 4). Only exercised by two-step methods (card/Klarna).
 */
interface MollieCaptureAdapterInterface
{
    public function createCapture(CaptureRequest $request): MollieCaptureDto;

    /**
     * All captures recorded against a payment — the admin transaction-history source of truth.
     *
     * @return list<MollieCaptureDto>
     */
    public function listCaptures(string $paymentId): array;
}
