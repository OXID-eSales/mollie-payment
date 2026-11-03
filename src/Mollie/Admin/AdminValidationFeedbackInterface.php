<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

/**
 * Feedback channel for admin Payment-tab validation failures.
 *
 * The panel's action handler ({@see MolliePaymentPanelProvider}) is void and the tab re-renders
 * after the POST — failures must survive exactly one render cycle. `rejectWithMessage()` on the action path,
 * `consume()` (read-and-clear) on render.
 */
interface AdminValidationFeedbackInterface
{
    /**
     * Store an already formatted message for the next render (MOL-15: admin free text validated with the
     * checkout rules is formatted by the tagged user-data formatter).
     */
    public function rejectWithMessage(string $orderId, string $message): void;

    /**
     * Reads and clears the stored messages for the order in one call so a subsequent render
     * never shows stale errors.
     *
     * @return list<string>
     */
    public function consume(string $orderId): array;
}
