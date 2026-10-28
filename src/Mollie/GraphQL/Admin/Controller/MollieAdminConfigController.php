<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Controller;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AdminMollieConfig;
use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AdminMollieConfigInput;
use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AdminMollieConfigPayload;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieAdminSettingsInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigModifierInterface;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

/**
 * The Mollie settings screen of the admin: `adminMollieConfig`, `adminMollieConfigModify`. GraphQL mapping only;
 * the rules, step-up and audit are in the services. #[Right] without #[Logged], like every admin operation, so that
 * a refusal carries an errorCode. Both need ADMIN_PAYMENT_CONFIG (payment-base's right for provider settings).
 */
final class MollieAdminConfigController
{
    public function __construct(
        private readonly MollieAdminSettingsInterface $settings,
        private readonly MollieConfigModifierInterface $modifier,
    ) {
    }

    /**
     * The Mollie settings; API keys only as `testKeySet` / `liveKeySet`.
     */
    #[Query]
    #[Right(PaymentRightsProvider::CONFIG)]
    public function adminMollieConfig(): AdminMollieConfig
    {
        return new AdminMollieConfig($this->settings->snapshot());
    }

    /**
     * Changes settings. API keys are write-only (empty = unchanged) and must carry the prefix of their mode; switching
     * to live and writing a key need a step-up.
     */
    #[Mutation]
    #[Right(PaymentRightsProvider::CONFIG)]
    public function adminMollieConfigModify(AdminMollieConfigInput $input): AdminMollieConfigPayload
    {
        return AdminMollieConfigPayload::of($this->modifier->modify($input->toChange()));
    }
}
