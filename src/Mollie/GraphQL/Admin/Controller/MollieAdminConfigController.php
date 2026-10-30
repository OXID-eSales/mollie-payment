<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Admin\Controller;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AdminMollieConfig;
use OxidEsales\Payments\Mollie\GraphQL\Admin\DataType\AdminMollieConnectionCheck;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieAdminSettingsInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConnectionCheckerInterface;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

/**
 * The Mollie settings as the admin reads them: `adminMollieConfig` and `adminMollieConnectionCheck`. Read-only: the
 * settings change in the classic admin only (CFG-1), there is no mutation. GraphQL mapping only. #[Right] without
 * #[Logged], like every admin operation, so that a refusal carries an errorCode. Both need ADMIN_PAYMENT_CONFIG
 * (payment-base's right for provider settings).
 */
final class MollieAdminConfigController
{
    public function __construct(
        private readonly MollieAdminSettingsInterface $settings,
        private readonly MollieConnectionCheckerInterface $connectionChecker,
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
     * Asks Mollie whether the API key of the active mode is accepted. No payload: only the verdict.
     */
    #[Query]
    #[Right(PaymentRightsProvider::CONFIG)]
    public function adminMollieConnectionCheck(): AdminMollieConnectionCheck
    {
        return new AdminMollieConnectionCheck($this->connectionChecker->check());
    }
}
