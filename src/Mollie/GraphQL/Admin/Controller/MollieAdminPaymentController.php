<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Controller;

use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentOperationPayload;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieReleaseOperationInterface;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Right;
use TheCodingMachine\GraphQLite\Types\ID;

/**
 * `adminMolliePaymentRelease`: Mollie's own operation of the Payment tab (MOL-32), answered like payment-base's
 * capture, refund and void (`AdminPaymentOperationPayload`). GraphQL mapping only; step-up, idempotency and audit
 * are in the service. #[Right] without #[Logged], like every admin operation.
 */
final class MollieAdminPaymentController
{
    public function __construct(private readonly MollieReleaseOperationInterface $release)
    {
    }

    /**
     * Releases what is left of a partially captured authorization back to the customer (Mollie has no partial release).
     * Allowed only while the payment is an open authorization with something captured and something left; guarded by
     * the capture right (it ends capturing) and a step-up. `reason` is free text (up to 140 characters);
     * `idempotencyKey` (8-64 of letters, digits, - and _) makes a retry report the earlier success.
     */
    #[Mutation]
    #[Right(PaymentRightsProvider::CAPTURE)]
    public function adminMolliePaymentRelease(
        ID $orderId,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): AdminPaymentOperationPayload {
        return AdminPaymentOperationPayload::of($this->release->release((string) $orderId, $reason, $idempotencyKey));
    }
}
