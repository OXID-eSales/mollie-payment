<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter;

use Mollie\Api\Endpoints\ProfileEndpoint;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Profile;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieConfigurationException;
use OxidEsales\Payments\Mollie\Adapter\MollieConnectionAdapter;
use OxidEsales\Payments\Mollie\Adapter\MollieConnectionAdapterFactory;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieConnectionAdapter::class)]
#[CoversClass(MollieConnectionAdapterFactory::class)]
final class MollieConnectionAdapterTest extends TestCase
{
    public function testVerifyConnection_ReadsTheProfileOfTheKey(): void
    {
        $profiles = $this->createMock(ProfileEndpoint::class);
        $profiles->expects(self::once())->method('getCurrent')->willReturn($this->createStub(Profile::class));

        (new MollieConnectionAdapter(fn (): MollieApiClient => $this->client($profiles)))->verifyConnection();
    }

    public function testVerifyConnection_AnSdkExceptionBecomesTheDomainExceptionWithTheHttpStatus(): void
    {
        $profiles = $this->createStub(ProfileEndpoint::class);
        $profiles->method('getCurrent')->willThrowException(new ApiException('Unauthorized', 401));

        try {
            (new MollieConnectionAdapter(fn (): MollieApiClient => $this->client($profiles)))->verifyConnection();
            self::fail('the refusal must surface');
        } catch (MollieAdapterException $domain) {
            self::assertSame(401, $domain->getCode());
        }
    }

    public function testFactory_TheClientIsBuiltOnFirstUse_NotWhenTheAdapterIsCreated(): void
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->expects(self::never())->method('getApiKey');

        $adapter = (new MollieConnectionAdapterFactory($config))->create();

        self::assertInstanceOf(MollieConnectionAdapter::class, $adapter);
    }

    public function testFactory_WithoutAKey_TheCheckFailsAsAConfigurationError(): void
    {
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);
        $config->method('getApiKey')->willReturn('');

        $this->expectException(MollieConfigurationException::class);

        (new MollieConnectionAdapterFactory($config))->create()->verifyConnection();
    }

    private function client(ProfileEndpoint $profiles): MollieApiClient
    {
        $client = $this->createStub(MollieApiClient::class);
        $client->profiles = $profiles;

        return $client;
    }
}
