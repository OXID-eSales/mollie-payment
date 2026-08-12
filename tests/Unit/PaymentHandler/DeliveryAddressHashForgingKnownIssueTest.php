<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\PaymentHandler;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use OxidEsales\Payments\Mollie\PaymentHandler\MolliePaymentHandler;

/**
 * Sprint 11 — F10, **deferred, documented, NOT fixed.**
 *
 * `MolliePaymentHandler::prepareOxidBasket()` writes
 * `$_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();`
 *
 * `sDeliveryAddressMD5` is OXID's guard against the delivery address changing between the address
 * step and order finalisation. Writing the *current* encoded address into `$_POST` server-side makes
 * the comparison compare a value with itself, so it can never fail again on the one-page-checkout
 * path. The method's docblock frames this as basket preparation ("so the early-order creation does
 * not reject with an invalid-delivery/user state") — which is a description of the check being
 * disabled.
 *
 * Why this is a test and not a fix: the correct behaviour is to pass the hash through from the OPC
 * request and let the guard fail when it should, which changes one-page-checkout's contract and needs
 * a decision from whoever owns that integration. Sprint 11 deliberately did not make that call.
 *
 * This test therefore pins the CURRENT behaviour so that:
 *  - the forging cannot be forgotten (it is named, in the suite, in a `known-issue` group);
 *  - whoever fixes it gets a failing test telling them the decision has been made, and where.
 *
 * Run `--exclude-group known-issue` to skip it; do not delete it without fixing F10.
 *
 * @see docs/dev_day_log/20260812/reports/01-unnecessary-and-dangerous-fallbacks.md — F10
 */
#[Group('known-issue')]
#[Group('F10')]
final class DeliveryAddressHashForgingKnownIssueTest extends TestCase
{
    public function testPrepareOxidBasketStillForgesTheDeliveryAddressHash(): void
    {
        $source = $this->sourceOf('prepareOxidBasket');

        self::assertStringContainsString(
            "\$_POST['sDeliveryAddressMD5']",
            $source,
            'F10 appears to be fixed — delete this known-issue test and record the decision in the '
            . 'dev log.',
        );
    }

    public function testTheForgingIsStillUnconditional(): void
    {
        $source = $this->sourceOf('prepareOxidBasket');

        // If a condition ever guards the write (e.g. only when OPC supplied no hash), this assertion
        // fails and the finding needs re-assessing rather than silently narrowing.
        self::assertStringNotContainsString(
            'sDeliveryAddressMD5\'])',
            $source,
            'the write appears to be conditional now — re-assess F10',
        );
    }

    private function sourceOf(string $method): string
    {
        $reflection = new ReflectionMethod(MolliePaymentHandler::class, $method);
        $file = (string) $reflection->getFileName();
        $lines = (array) file($file);

        return implode(
            '',
            array_slice(
                $lines,
                (int) $reflection->getStartLine() - 1,
                (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1,
            ),
        );
    }
}
