<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Admin;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;

/**
 * MOL-32 S6: release what is left of an authorization after partial captures. Mollie-only: payment-base's money
 * runner (capture, refund, void) does not know this action, so the classic Payment tab and the admin API's
 * `adminMolliePaymentRelease` both end here.
 */
interface ReleaseActionInterface
{
    /**
     * @throws AdminActionRefusedException a release that is not possible, a reason the rules refuse, or Mollie failing
     */
    public function run(string $orderId, ?string $reason): void;
}
