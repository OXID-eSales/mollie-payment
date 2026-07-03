<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use RuntimeException;

/**
 * Generates and validates HMAC-secured tokens for passing a contract id in the Mollie
 * `redirectUrl`, without exposing the raw id to tampering.
 *
 * Token format: base64url(contractId:hmac). The HMAC key is itself derived (via a second HMAC)
 * from the module's configured Mollie API key, so no separate secret needs to be provisioned.
 */
final class ContractTokenService implements TokenServiceInterface
{
    private const TOKEN_SEPARATOR = ':';
    private const HASH_ALGORITHM = 'sha256';
    private const TOKEN_SALT = 'oe_mollie_contract_token_v1';

    private ?string $secret = null;

    public function __construct(
        private readonly ModuleConfigurationServiceInterface $configService,
    ) {
    }

    public function generateToken(string $contractId): string
    {
        $hmac = $this->generateHmac($contractId);

        return $this->base64UrlEncode($contractId . self::TOKEN_SEPARATOR . $hmac);
    }

    public function validateToken(string $token, string $contractId): bool
    {
        if ($token === '') {
            return false;
        }

        $extractedId = $this->extractContractId($token);

        return $extractedId !== null && hash_equals($contractId, $extractedId);
    }

    public function extractContractId(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        $decoded = $this->base64UrlDecode($token);
        if ($decoded === false) {
            return null;
        }

        $parts = explode(self::TOKEN_SEPARATOR, $decoded, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$contractId, $hmac] = $parts;
        if (!hash_equals($this->generateHmac($contractId), $hmac)) {
            return null;
        }

        return $contractId;
    }

    private function generateHmac(string $contractId): string
    {
        return hash_hmac(self::HASH_ALGORITHM, $contractId, $this->getSecret());
    }

    private function getSecret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }

        $apiKey = $this->configService->getApiKey();
        if ($apiKey === '') {
            throw new RuntimeException(
                'Mollie contract token service requires a configured API key '
                . '(sMollieTestKey/sMollieLiveKey).',
            );
        }

        $this->secret = hash_hmac(self::HASH_ALGORITHM, self::TOKEN_SALT, $apiKey);

        return $this->secret;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
