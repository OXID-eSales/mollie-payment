<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\CreatePaymentRequest;

/**
 * Builds the {@see CreatePaymentRequest} for a contract. Pure assembly — no adapter call, no
 * contract mutation. {@see \OxidEsales\Payments\Mollie\EventSystem\Handler\MollieCheckoutSessionHandler}
 * is the only consumer; it owns calling the adapter and updating the contract.
 */
interface CheckoutPaymentServiceInterface
{
    public function buildCreatePaymentRequest(
        PaymentContractInterface $contract,
        ?string $method,
        string $redirectUrl,
    ): CreatePaymentRequest;
}
