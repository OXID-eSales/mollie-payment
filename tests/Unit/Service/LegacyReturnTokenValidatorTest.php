<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use LogicException;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Service\LegacyReturnTokenValidator;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * VR-20 R3 - the module's own return token is gone: new return URLs carry payment-base's shared token. A payment page
 * opened before the update still returns with the old HMAC token (contract id + HMAC keyed by the Mollie API key), and
 * payment-base accepts it for one release while `blPaymentBaseAcceptLegacyReturnTokens` is on. This validates such a
 * token and never issues one.
 */
#[CoversClass(LegacyReturnTokenValidator::class)]
final class LegacyReturnTokenValidatorTest extends TestCase
{
    private const API_KEY = 'test_secret_key';

    public function testItIsTheLegacyTokenServiceThePaymentBaseGuardAsksFor(): void
    {
        self::assertInstanceOf(TokenServiceInterface::class, $this->validator(self::API_KEY));
    }

    public function testATokenIssuedBeforeTheUpdateValidates(): void
    {
        self::assertTrue($this->validator(self::API_KEY)->validateToken(self::legacyToken('contract-1', self::API_KEY), 'contract-1'));
    }

    public function testATokenOfAnotherContractDoesNotValidate(): void
    {
        self::assertFalse($this->validator(self::API_KEY)->validateToken(self::legacyToken('contract-1', self::API_KEY), 'contract-2'));
    }

    public function testATamperedTokenDoesNotValidate(): void
    {
        $token = self::legacyToken('contract-1', self::API_KEY);
        $tampered = substr($token, 0, -2) . 'zz';

        self::assertFalse($this->validator(self::API_KEY)->validateToken($tampered, 'contract-1'));
    }

    public function testATokenSignedWithAnotherApiKeyDoesNotValidate(): void
    {
        self::assertFalse($this->validator('key_b')->validateToken(self::legacyToken('contract-1', 'key_a'), 'contract-1'));
    }

    public function testAnEmptyTokenDoesNotValidate(): void
    {
        self::assertFalse($this->validator(self::API_KEY)->validateToken('', 'contract-1'));
    }

    public function testTheContractIdIsReadOnlyFromAnAuthenticTokenAndGarbageIsNull(): void
    {
        $validator = $this->validator(self::API_KEY);

        self::assertSame('contract-1', $validator->extractContractId(self::legacyToken('contract-1', self::API_KEY)));
        self::assertNull($validator->extractContractId('not-a-valid-token'));
    }

    public function testWithoutAnApiKeyNothingValidates(): void
    {
        $this->expectException(RuntimeException::class);

        $this->validator('')->validateToken(self::legacyToken('contract-1', self::API_KEY), 'contract-1');
    }

    public function testItNeverIssuesAToken(): void
    {
        $this->expectException(LogicException::class);

        $this->validator(self::API_KEY)->generateToken('contract-1');
    }

    /**
     * The token as the module issued it before VR-20 R3: base64url(contractId:hmac), the HMAC keyed by a second HMAC of
     * a fixed salt under the configured Mollie API key.
     */
    public static function legacyToken(string $contractId, string $apiKey): string
    {
        $secret = hash_hmac('sha256', 'oe_mollie_contract_token_v1', $apiKey);
        $hmac = hash_hmac('sha256', $contractId, $secret);

        return rtrim(strtr(base64_encode($contractId . ':' . $hmac), '+/', '-_'), '=');
    }

    private function validator(string $apiKey): LegacyReturnTokenValidator
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getApiKey')->willReturn($apiKey);

        return new LegacyReturnTokenValidator($config);
    }
}
