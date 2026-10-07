<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\GraphQL\Controller;

use OxidEsales\GraphQL\Base\Service\Authentication;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartRequest;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutCancelResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutReturnResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutStartResult;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\GraphQL\Exception\MollieCheckoutError;
use RuntimeException;
use TheCodingMachine\GraphQLite\Annotations\Logged;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Right;
use TheCodingMachine\GraphQLite\Types\ID;

/**
 * The Mollie checkout for the GraphQL Storefront (GRAPH-QL / MS4, Option B):
 * core `placeOrder` is not used for Mollie baskets; these three mutations are.
 *
 *   mollieCheckoutStart(basketId, confirmTermsAndConditions, returnUrl, cancelUrl?, method?, uiMode?)
 *       → { contractId, contractToken, orderNumber, redirectUrl, renderMode: redirect }
 *   … shopper pays on Mollie's hosted page; Mollie sends them to returnUrl?contract_id=… …
 *   mollieCheckoutReturn(contractId, contractToken) → { status, orderId, orderNumber, contractState }
 *   mollieCheckoutCancel(contractId, contractToken) → { cancelled, contractId, contractState }
 *
 * Mollie knows one redirect URL for every outcome, so `cancelUrl` is accepted
 * for API symmetry and not used; a cancelled or failed payment returns to
 * `returnUrl` too and `mollieCheckoutReturn` answers `failed`. `method` is a
 * hint (ideal, creditcard, klarna, …): without it Mollie's page offers every
 * method. `uiMode` is `hosted` only (Mollie's page refuses framing).
 *
 * Everything provider-neutral lives in payment-base's HeadlessCheckoutService;
 * this class adds the JWT user and the Mollie payment id, and turns refusals
 * into client-safe errors. The return needs no provider parameters: the
 * resolver asks Mollie by the contract's payment id.
 *
 * @since 3.4.0
 */
final class MollieCheckout
{
    public function __construct(
        private readonly HeadlessCheckoutServiceInterface $checkout,
        private readonly ?Authentication $authentication = null,
    ) {
    }

    #[Mutation]
    #[Logged]
    #[Right('PAYMENT_CHECKOUT')]
    public function mollieCheckoutStart(
        ID $basketId,
        bool $confirmTermsAndConditions,
        string $returnUrl,
        ?string $cancelUrl = null,
        ?string $method = null,
        string $uiMode = 'hosted'
    ): CheckoutStartResult {
        try {
            return new CheckoutStartResult($this->checkout->start(new HeadlessStartRequest(
                userId: $this->userId(),
                basketId: (string) $basketId,
                confirmTermsAndConditions: $confirmTermsAndConditions,
                returnUrl: $returnUrl,
                cancelUrl: $cancelUrl,
                uiMode: $uiMode,
                // This mutation IS Mollie's: a basket the client never ran through
                // basketSetPayment still starts; one set to another payment is refused.
                paymentId: MollieDefinitions::PAYMENT_ID,
                providerOptions: $method !== null && $method !== '' ? ['mollieMethod' => $method] : [],
            )));
        } catch (HeadlessCheckoutException $e) {
            throw new MollieCheckoutError($e);
        }
    }

    #[Mutation]
    #[Logged]
    #[Right('PAYMENT_CHECKOUT')]
    public function mollieCheckoutReturn(string $contractId, string $contractToken): CheckoutReturnResult
    {
        try {
            return new CheckoutReturnResult($this->checkout->return($contractId, $contractToken, []));
        } catch (HeadlessCheckoutException $e) {
            throw new MollieCheckoutError($e);
        }
    }

    #[Mutation]
    #[Logged]
    #[Right('PAYMENT_CHECKOUT')]
    public function mollieCheckoutCancel(string $contractId, string $contractToken): CheckoutCancelResult
    {
        try {
            return new CheckoutCancelResult($this->checkout->cancel($contractId, $contractToken));
        } catch (HeadlessCheckoutException $e) {
            throw new MollieCheckoutError($e);
        }
    }

    private function userId(): string
    {
        if ($this->authentication === null) {
            throw new RuntimeException('graphql-base is not installed; the Mollie checkout mutations need it');
        }

        return (string) $this->authentication->getUser()->id();
    }
}
