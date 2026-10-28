<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Relation;

use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentContract;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AdminMolliePaymentDetails;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MolliePaymentDetailsReaderInterface;
use TheCodingMachine\GraphQLite\Annotations\ExtendType;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Right;

/**
 * Adds `mollie` to payment-base's `AdminPaymentContract`: the Mollie facts of the payment (method, Mollie's status,
 * release figures, dashboard link), read live from Mollie. Null for a contract of another provider. Read-only,
 * behind the same right as the Payment tab itself (ADMIN_PAYMENT_VIEW).
 *
 * Mapped by NamespaceMapper only when the admin API and payment-base's admin payment API are installed; never
 * implements or extends anything of those optional packages (the container reflects the class at module activation).
 */
#[ExtendType(class: AdminPaymentContract::class)]
final class AdminPaymentContractMollieRelation
{
    public function __construct(private readonly MolliePaymentDetailsReaderInterface $details)
    {
    }

    #[Field(name: 'mollie')]
    #[Right(PaymentRightsProvider::VIEW)]
    public function mollie(AdminPaymentContract $contract): ?AdminMolliePaymentDetails
    {
        $details = $this->details->forContract($contract->contractId());

        return $details === null ? null : new AdminMolliePaymentDetails($details);
    }
}
