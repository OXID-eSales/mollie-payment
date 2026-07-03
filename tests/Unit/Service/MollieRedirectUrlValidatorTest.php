<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\MollieRedirectUrlValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 8 Story 1 (F14 — open redirect parity with PayPal's PayerActionUrlValidator).
 *
 * Mollie's `checkoutUrl` is returned by an authenticated create-payment API response (TLS +
 * secret key), not by an inbound/forgeable endpoint — a materially narrower threat model than
 * PayPal's payer-action HATEOAS link. Still, defense-in-depth costs little: a compromised Mollie
 * account, a MITM'd TLS session, or a future SDK bug could otherwise redirect the shopper
 * anywhere. This validator pins the accepted host to mollie.com and its subdomains.
 */
#[CoversClass(MollieRedirectUrlValidator::class)]
final class MollieRedirectUrlValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'attacker.com direct' => ['https://attacker.com/steal', false];
        yield 'attacker + mollie path' => ['https://attacker.com/mollie.com/pay', false];
        yield 'look-alike suffix' => ['https://attackermollie.com/', false];
        yield 'userinfo trick' => ['https://www.mollie.com@attacker.com/', false];
        yield 'javascript pseudo' => ['javascript:alert(1)', false];
        yield 'data pseudo' => ['data:text/html,<script>', false];
        yield 'http (no TLS)' => ['http://www.mollie.com/checkout/tr_x', false];
        yield 'protocol-relative' => ['//www.mollie.com/checkout/tr_x', false];
        yield 'empty string' => ['', false];
        yield 'legit apex domain' => ['https://mollie.com/checkout/select-method/tr_x', true];
        yield 'legit www subdomain' => ['https://www.mollie.com/checkout/select-method/tr_x', true];
        yield 'legit checkout subdomain' => ['https://checkout.mollie.com/tr_x', true];
    }

    #[DataProvider('urls')]
    public function testIsAllowed(string $url, bool $expected): void
    {
        self::assertSame($expected, (new MollieRedirectUrlValidator())->isAllowed($url));
    }
}
