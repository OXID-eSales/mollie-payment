<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter;

use Mollie\Api\MollieApiClient;
use OxidEsales\Payments\Mollie\Adapter\MollieClientFactory;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieClientFactory::class)]
final class MollieClientFactoryTest extends TestCase
{
    private const VALID_TEST_KEY = 'test_dummydummydummydummydummydummy';

    public function testCreate_BuildsClientWithApiKey(): void
    {
        $client = (new MollieClientFactory(self::VALID_TEST_KEY))->create();
        self::assertInstanceOf(MollieApiClient::class, $client);
    }

    public function testCreate_ThrowsConfigExceptionWhenKeyMissing(): void
    {
        $this->expectException(MollieConfigurationException::class);
        (new MollieClientFactory(''))->create();
    }

    public function testCreate_ThrowsConfigExceptionWhenKeyMalformed_NoSdkExceptionLeaks(): void
    {
        $this->expectException(MollieConfigurationException::class);
        (new MollieClientFactory('not-a-valid-key'))->create();
    }
}
