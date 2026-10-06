<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Mcp;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Mcp\Acp\AbstractAcpCheckoutService;
use OxidEsales\PaymentBase\Mcp\AgentContextInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;

/**
 * Mollie's ACP checkout service (GRAPH-QL / MS5) on payment-base's headless
 * path. `create_checkout` is the base class's default: the agent's buyer
 * becomes a shop user, the items a user basket paying with Mollie, and the
 * contract is opened through the same chain as every checkout (early order,
 * PENDING).
 *
 * `complete_checkout` is **refused**: Mollie has no server-side charge of a
 * delegated payment token. A Mollie card token is minted by Mollie Components
 * in the shopper's browser and is single-use for that session; every other
 * method needs Mollie's hosted page. An agent therefore hands the buyer to
 * `mollieCheckoutStart`'s redirect URL and the webhook ends the order (MS3).
 *
 * Reachability: the MCP / UCP transport (server, tools, auth guard,
 * controllers) is a separate feature; this is the provider half it needs.
 *
 * @since 3.4.0
 */
final class MollieAcpCheckoutService extends AbstractAcpCheckoutService
{
    public const COMPLETE_NOT_SUPPORTED = 'Mollie cannot charge a delegated payment token: the buyer pays on Mollie\'s '
        . 'hosted page (mollieCheckoutStart → redirectUrl); the webhook ends the order';

    protected function paymentId(): string
    {
        return MollieDefinitions::PAYMENT_ID;
    }

    protected function providerName(): string
    {
        return MollieDefinitions::PROVIDER_NAME;
    }

    protected function completePayment(
        PaymentContractInterface $contract,
        array $paymentData,
        AgentContextInterface $agentContext
    ): array {
        return $this->formatter->validationError(self::COMPLETE_NOT_SUPPORTED, 'payment_data.token');
    }
}
