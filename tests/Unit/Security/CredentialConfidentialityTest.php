<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Security;

use OxidEsales\Payments\Mollie\Adapter\Exception\MollieConfigurationException;
use OxidEsales\Payments\Mollie\Adapter\MollieClientFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 8 Story 1 — F19/F23 parity (sensitive data in exceptions / exception logging).
 *
 * PayPal's OAuth2 flow carries bearer tokens and client secrets that must be redacted from log
 * context — Mollie's only credential is a single API key, and unlike PayPal there is no
 * `RequestLogService`-style redaction layer in this module because there is nothing to redact:
 * the key is validated by format only (prefix + length) and never echoed back by the Mollie API
 * or interpolated into any exception message this module constructs. These tests pin that
 * property so a future change can't silently start leaking it.
 */
#[Group('security')]
#[Group('F19')]
#[Group('F23')]
final class CredentialConfidentialityTest extends TestCase
{
    private const SECRET_MARKER = 'zzTOPSECRETzz789';

    // 2026-10-14 (review B2): the ConfigurationValidator leak test left with the validator
    // itself — the class had no production caller, so its error strings could not leak anything.
    // MollieClientFactory remains the one place a malformed key is turned into a message.

    public function testClientFactoryMalformedKeyExceptionNeverContainsTheKeyValue(): void
    {
        $key = 'not_a_valid_prefix_' . self::SECRET_MARKER;

        try {
            (new MollieClientFactory($key))->create();
            self::fail('Expected a MollieConfigurationException for a malformed key.');
        } catch (MollieConfigurationException $e) {
            self::assertStringNotContainsString(self::SECRET_MARKER, $e->getMessage());
            self::assertStringNotContainsString($key, $e->getMessage());
        }
    }

    public function testClientFactoryEmptyKeyExceptionNeverContainsAConfiguredValue(): void
    {
        try {
            (new MollieClientFactory(''))->create();
            self::fail('Expected a MollieConfigurationException for an empty key.');
        } catch (MollieConfigurationException $e) {
            self::assertStringNotContainsString(self::SECRET_MARKER, $e->getMessage());
        }
    }
}
