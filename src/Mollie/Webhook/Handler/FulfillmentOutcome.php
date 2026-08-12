<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

/**
 * What a {@see WebhookContractFulfillmentHandlerInterface} method actually did.
 *
 * Sprint 11 Story 1 (F2). These methods previously returned a tri-state `?bool`, and that shape is
 * what caused the bug: `false` meant BOTH "already fulfilled / already terminal — nothing to do"
 * and "we tried and could not complete it". Both were mapped to `WebhookResult::skipped()`, whose
 * `success` flag is `true`, so both were answered `HTTP 200` — and Mollie only retries on non-2xx.
 * A contract that failed to commit was therefore dropped for good, silently, with a `skipped` row
 * in the webhook log.
 *
 * Four cases, four responses:
 *
 * - {@see self::Acted}           → 200, work done
 * - {@see self::NoOp}            → 200, safely nothing to do (duplicate/late delivery)
 * - {@see self::Failed}          → 5xx, retry may succeed
 * - {@see self::ContractNotFound} → 5xx while the payment is young enough that our own commit may
 *                                   still be in flight, 200 + warning afterwards (see D3 and
 *                                   {@see AbstractMollieWebhookHandler::CONTRACT_WAIT_WINDOW_SECONDS})
 */
enum FulfillmentOutcome
{
    /** No contract is indexed by this provider order id (yet). */
    case ContractNotFound;

    /** The transition ran and the contract advanced. */
    case Acted;

    /** A state guard declined the work because it was already done — safe and terminal. */
    case NoOp;

    /** The work was attempted and did not complete. Retry-worthy. */
    case Failed;
}
