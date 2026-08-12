<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieWebhookUrlProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Sprint 11 Story 7 (F20) — webhook-URL derivation, moved out of `ModuleConfigurationService`
 * (which reached into `Registry::getConfig()` to do it, against this module's own DI rules, and
 * cannot take a `ShopAdapterInterface` without closing a dependency cycle).
 */
#[CoversClass(MollieWebhookUrlProvider::class)]
#[Group('F20')]
final class MollieWebhookUrlProviderTest extends TestCase
{
    public function testMerchantOverrideWins(): void
    {
        $provider = $this->provider(override: 'https://hooks.shop.test/mollie');

        self::assertSame('https://hooks.shop.test/mollie', $provider->getWebhookUrl());
    }

    public function testDerivesFromShopUrlWhenNoOverride(): void
    {
        $provider = $this->provider(override: '', shopUrl: 'https://shop.test/');

        self::assertSame(
            'https://shop.test/index.php?cl=' . MollieDefinitions::WEBHOOK_CONTROLLER_ID,
            $provider->getWebhookUrl(),
        );
    }

    public function testDerivedUrlDoesNotDoubleTheSlash(): void
    {
        $provider = $this->provider(override: '', shopUrl: 'https://shop.test');

        self::assertStringNotContainsString('//index.php', $provider->getWebhookUrl());
    }

    /**
     * Mollie will not deliver to a plaintext endpoint, and a webhook Mollie cannot reach produces
     * exactly the symptoms of F1/F2 — orders that never finalize — with none of the evidence.
     */
    public function testNonHttpsUrlIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('not HTTPS'), self::anything());

        $this->provider(override: '', shopUrl: 'http://shop.test', logger: $logger)->getWebhookUrl();
    }

    public function testHttpsUrlLogsNothing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->provider(override: 'https://shop.test/hook', logger: $logger)->getWebhookUrl();
    }

    private function provider(
        string $override = '',
        string $shopUrl = 'https://shop.test',
        ?LoggerInterface $logger = null,
    ): MollieWebhookUrlProvider {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getWebhookUrlOverride')->willReturn($override);

        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopUrl')->willReturn($shopUrl);

        return new MollieWebhookUrlProvider($config, $shopAdapter, $logger ?? new NullLogger());
    }
}
