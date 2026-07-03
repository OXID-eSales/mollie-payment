<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

/**
 * Provider-neutral interpretation of a Mollie payment status. Webhook/return handlers switch on
 * this instead of scattering raw Mollie status strings across the codebase.
 */
enum MollieOutcome
{
    case PAID;        // funds captured -> drive contract toward fulfilled
    case AUTHORIZED;  // authorized, awaiting capture (two-step)
    case PENDING;     // open/pending -> leave for the webhook, do not finalize
    case CANCELED;    // customer/merchant canceled
    case EXPIRED;     // payment window elapsed
    case FAILED;      // payment failed
    case IGNORED;     // unknown/irrelevant status -> safe no-op
}
