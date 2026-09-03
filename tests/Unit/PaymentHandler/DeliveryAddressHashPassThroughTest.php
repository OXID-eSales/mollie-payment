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
 * F10 RESOLVED — the delivery-address hash is passed through, not forged.
 *
 * Replaces `DeliveryAddressHashForgingKnownIssueTest`, which pinned the old behaviour and said in
 * its own failure message: "F10 appears to be fixed — delete this known-issue test and record the
 * decision in the dev log." Both done; the decision is written up in
 * docs/dev_day_log/20260903/reports/01-f10-delivery-address-hash.md.
 *
 * `prepareOxidBasket()` used to write
 * `$_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();`, which failed twice over.
 * It disabled OXID's guard — the comparison compared a value with itself — and the forged value
 * was incomplete, because `Order::validateDeliveryAddress()` appends the selected `oxaddress`
 * row's own encoded form whenever one is selected. Measured on the local shop:
 *
 *     submitted : 42ce5cac3ff87aa8ec84090e20537a05                                  (32 chars)
 *     expected  : 42ce5cac3ff87aa8ec84090e20537a05477ebb5de0f9ec70d1d5cd251bf5644f  (64 chars)
 *
 * Both required-field sets validated, so that mismatch was the sole remaining cause of
 * `finalizeOrder` state 7. Harmless only while no `oxaddress` row existed; OPC-156 started
 * writing one and OPC-217 made one exist in nearly every flow.
 *
 * OPC supplies the correct hash itself, immediately before dispatching to this handler
 * (`CheckoutService::prepareDeliveryAddressMd5()`), so the fix is to stop overwriting it.
 *
 * Source-level rather than behavioural, deliberately and for the same reason its predecessor was:
 * `prepareOxidBasket()` reaches the OXID Registry and a session, and the point here is the absence
 * of one specific assignment — which is a property of the code, not of a run.
 */
#[Group('F10')]
final class DeliveryAddressHashPassThroughTest extends TestCase
{
    public function testPrepareOxidBasketNeverWritesTheDeliveryAddressHash(): void
    {
        $source = $this->sourceOf('prepareOxidBasket');

        // Assert on the ASSIGNMENT, not on the mere mention of the key: the docblock names it
        // several times explaining why it is absent, and a substring test for the key alone would
        // fail on the documentation.
        self::assertDoesNotMatchRegularExpression(
            '/\$_POST\s*\[\s*[\'"]sDeliveryAddressMD5[\'"]\s*\]\s*=/',
            $source,
            'the handler is forging the delivery-address hash again — that disables OXID\'s '
            . 'change guard AND sends a value that is short by the oxaddress row, which is '
            . 'finalizeOrder state 7. Let OPC\'s hash stand; see F10.',
        );
    }

    public function testTheUserIsStillWiredIntoTheBasket(): void
    {
        // The half of the old line that WAS legitimate. Dropping `setBasketUser` along with the
        // forging would trade state 7 for a different finalisation failure.
        $source = $this->sourceOf('prepareOxidBasket');

        self::assertStringContainsString(
            'setBasketUser($user)',
            $source,
            'prepareOxidBasket must still put the user on the basket',
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
                $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1,
            ),
        );
    }
}
