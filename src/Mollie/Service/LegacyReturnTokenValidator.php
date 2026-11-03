<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use LogicException;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use RuntimeException;

/**
 * The module's return token before VR-20 R3, validated and never issued. New return URLs carry payment-base's shared
 * return token; a Mollie payment page opened before the update still returns with the old `contract_token`
 * (base64url(contractId:hmac), the HMAC keyed by a second HMAC of a fixed salt under the configured Mollie API key).
 * payment-base's `ReturnAccessGuard` accepts such a token for one release, only while the setting
 * `blPaymentBaseAcceptLegacyReturnTokens` is on and only when the provider hands this validator over; delete this class
 * with that switch.
 */
final class LegacyReturnTokenValidator implements TokenServiceInterface
{
    private const TOKEN_SEPARATOR = ':';
    private const HASH_ALGORITHM = 'sha256';
    private const TOKEN_SALT = 'oe_mollie_contract_token_v1';

    private ?string $secret = null;

    public function __construct(private readonly ModuleConfigurationServiceInterface $configService)
    {
    }

    public function generateToken(string $contractId): string
    {
        throw new LogicException(
            'The legacy Mollie return token is validated, never issued; use ContractReturnTokenServiceInterface.'
        );
    }

    public function validateToken(string $token, string $contractId): bool
    {
        $extractedId = $this->extractContractId($token);

        return $extractedId !== null && hash_equals($contractId, $extractedId);
    }

    public function extractContractId(string $token): ?string
    {
        if ($token === '') {
            return null;
        }
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        $parts = explode(self::TOKEN_SEPARATOR, $decoded, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$contractId, $hmac] = $parts;

        return hash_equals($this->hmacOf($contractId), $hmac) ? $contractId : null;
    }

    private function hmacOf(string $contractId): string
    {
        return hash_hmac(self::HASH_ALGORITHM, $contractId, $this->secret());
    }

    private function secret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }
        $apiKey = $this->configService->getApiKey();
        if ($apiKey === '') {
            throw new RuntimeException(
                'The legacy Mollie return token needs a configured API key (sMollieTestKey/sMollieLiveKey).'
            );
        }

        return $this->secret = hash_hmac(self::HASH_ALGORITHM, self::TOKEN_SALT, $apiKey);
    }
}
