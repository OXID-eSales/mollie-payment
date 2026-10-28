<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Service;

/**
 * Mollie's verdict on the API key of the active mode: nothing more than the verdict and the mode it was asked for.
 */
final readonly class MollieConnectionStatus
{
    /** Mollie accepted the key. */
    public const OK = 'ok';
    /** Mollie refused the key (revoked, wrong account, malformed). */
    public const INVALID_KEY = 'invalid_key';
    /** Mollie could not be asked: no answer, so nothing may be concluded about the key. */
    public const UNREACHABLE = 'unreachable';
    /** There is no key for the active mode (or the settings cannot be read). */
    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(public string $status, public string $mode)
    {
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
