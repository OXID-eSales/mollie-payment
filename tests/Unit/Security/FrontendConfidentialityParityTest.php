<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Security;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieMethodDto;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Sprint 8 Story 1 — F21 parity (order id / secret confidentiality at the frontend layer).
 *
 * PayPal's F21 concerned its `paypal_order_id` / client-id leaking through the checkout widget.
 * Mollie has no client-side SDK widget at all (see `docs/architecture/03-provider-abstraction.md`
 * — Mollie is a server-side redirect flow): the only Mollie-sourced data rendered on the
 * storefront is the method picker (`views/twig/frontend/mollie_methods.html.twig`), which is fed
 * exclusively by {@see MollieMethodDto}. This test pins that DTO's shape so a future change can't
 * accidentally widen it to carry a payment id, contract id, or the merchant's API key onto a
 * publicly rendered page.
 */
#[Group('security')]
#[Group('F21')]
final class FrontendConfidentialityParityTest extends TestCase
{
    public function testMollieMethodDtoOnlyExposesPresentationFields(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(MollieMethodDto::class))->getProperties(),
        );
        sort($properties);

        self::assertSame(['description', 'id', 'imageUrl'], $properties);
    }
}
