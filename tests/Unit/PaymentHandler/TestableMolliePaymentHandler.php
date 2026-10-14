<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\PaymentHandler;

use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\Payments\Mollie\PaymentHandler\MolliePaymentHandler;
use OxidEsales\Payments\Mollie\Service\BasketBuyabilityValidator;
use OxidEsales\Payments\Mollie\Service\CheckoutSessionStarter;
use OxidEsales\Payments\Mollie\Service\InFlightCheckoutReplayInterface;
use OxidEsales\Payments\Mollie\Service\NotOrderableItemsMessages;
use OxidEsales\Payments\Mollie\Tests\Unit\Support\FixedUserDataGate;
use Psr\Log\LoggerInterface;

/**
 * Overrides the two OXID-Registry-bound seams so the handler's ladder → result logic can be
 * unit-tested without a shop bootstrap, and composes the REAL {@see CheckoutSessionStarter}
 * around the test's dispatcher / validator / replay — the handler is exercised with the ladder
 * it ships with.
 */
final class TestableMolliePaymentHandler extends MolliePaymentHandler
{
    /**
     * @param list<string> $userDataProblems
     */
    public function __construct(
        EventDispatcherInterface $dispatcher,
        ?LoggerInterface $logger = null,
        ?IframeCheckoutSettingsInterface $iframeSettings = null,
        ?BasketBuyabilityValidator $buyability = null,
        ?NotOrderableItemsMessages $notOrderableMessages = null,
        ?InFlightCheckoutReplayInterface $replay = null,
        array $userDataProblems = [],
    ) {
        parent::__construct(
            new CheckoutSessionStarter(
                $dispatcher,
                $buyability ?? new BasketBuyabilityValidator(),
                new FixedUserDataGate($userDataProblems),
                $replay,
            ),
            $logger,
            $iframeSettings,
            $notOrderableMessages,
        );
    }

    protected function prepareOxidBasket(PaymentContextInterface $context): void
    {
        // no-op: no OXID session/basket in unit tests
    }

    protected function sessionId(): string
    {
        // The real seam reads the PHP session id (Registry-backed).
        return 'sid-test';
    }

    /**
     * Expose the pure metadata reader so it can be unit-tested without a shop bootstrap.
     *
     * @return array{selectedMethod: ?string, cardToken: ?string}
     */
    public function exposeMollieParams(PaymentContextInterface $context): array
    {
        return $this->mollieParamsFromContext($context);
    }
}
