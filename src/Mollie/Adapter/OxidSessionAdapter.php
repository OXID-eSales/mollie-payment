<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;

/**
 * OXID implementation of payment-base SessionAdapterInterface. Wraps {@see Registry::getSession()}
 * so handlers/services stay unit-testable without the OXID container. Ported from PayPal.
 */
class OxidSessionAdapter implements SessionAdapterInterface
{
    public function getSessionId(): string
    {
        return (string) Registry::getSession()->getId();
    }

    /** @phpstan-ignore return.unusedType (interface allows null, OXID always returns a basket) */
    public function getBasket(): ?object
    {
        return Registry::getSession()->getBasket();
    }

    public function setVariable(string $name, mixed $value): void
    {
        Registry::getSession()->setVariable($name, $value);
    }

    public function getVariable(string $name): mixed
    {
        return Registry::getSession()->getVariable($name);
    }

    public function setBasket(object $basket): void
    {
        Registry::getSession()->setBasket($basket);
    }

    public function setUser(object $user): void
    {
        Registry::getSession()->setVariable('oePaymentUser', $user);
    }
}
