<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Support;

use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Mollie\Service\BasketBuyabilityValidator;
use OxidEsales\Payments\Mollie\Service\CheckoutSessionStarter;
use OxidEsales\Payments\Mollie\Service\CheckoutStartRequest;
use OxidEsales\Payments\Mollie\Service\InFlightCheckoutReplayInterface;
use OxidEsales\Payments\Mollie\Service\NotOrderableCheckoutFailure;

/**
 * The REAL {@see CheckoutSessionStarter} ladder with its collaborators scripted: the buyability
 * verdict is handed in directly (preserving instance identity for assertSame), the user-data gate
 * answers a fixed list, the replay is whatever the test wires. The rung ORDER stays the real one —
 * entry-point tests exercise the ladder they ship with, not a re-implementation.
 */
final class ScriptedLadderStarter extends CheckoutSessionStarter
{
    /**
     * @param list<string> $userDataProblems
     */
    public function __construct(
        EventDispatcherInterface $dispatcher,
        private readonly ?NotOrderableCheckoutFailure $notOrderable = null,
        array $userDataProblems = [],
        ?InFlightCheckoutReplayInterface $replay = null,
    ) {
        parent::__construct(
            $dispatcher,
            new BasketBuyabilityValidator(),
            new FixedUserDataGate($userDataProblems),
            $replay,
        );
    }

    protected function notOrderableFailure(CheckoutStartRequest $request): ?NotOrderableCheckoutFailure
    {
        return $this->notOrderable;
    }
}
