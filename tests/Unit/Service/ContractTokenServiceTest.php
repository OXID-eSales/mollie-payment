<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\ContractTokenService;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ContractTokenService::class)]
final class ContractTokenServiceTest extends TestCase
{
    public function testGenerateTokenThenValidateTokenSucceeds(): void
    {
        $service = $this->service('test_secret_key');

        $token = $service->generateToken('contract-1');

        self::assertTrue($service->validateToken($token, 'contract-1'));
    }

    public function testValidateTokenRejectsWrongContractId(): void
    {
        $service = $this->service('test_secret_key');

        $token = $service->generateToken('contract-1');

        self::assertFalse($service->validateToken($token, 'contract-2'));
    }

    public function testValidateTokenRejectsTamperedToken(): void
    {
        $service = $this->service('test_secret_key');

        $token = $service->generateToken('contract-1');
        $tampered = substr($token, 0, -2) . 'zz';

        self::assertFalse($service->validateToken($tampered, 'contract-1'));
    }

    public function testValidateTokenRejectsEmptyToken(): void
    {
        self::assertFalse($this->service('test_secret_key')->validateToken('', 'contract-1'));
    }

    public function testExtractContractIdReturnsNullForGarbageInput(): void
    {
        self::assertNull($this->service('test_secret_key')->extractContractId('not-a-valid-token'));
    }

    public function testDifferentApiKeysProduceDifferentTokens(): void
    {
        $tokenA = $this->service('key_a')->generateToken('contract-1');
        $tokenB = $this->service('key_b')->generateToken('contract-1');

        self::assertNotSame($tokenA, $tokenB);
    }

    public function testGenerateTokenThrowsWhenApiKeyMissing(): void
    {
        $service = $this->service('');

        $this->expectException(RuntimeException::class);

        $service->generateToken('contract-1');
    }

    private function service(string $apiKey): ContractTokenService
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getApiKey')->willReturn($apiKey);

        return new ContractTokenService($config);
    }
}
