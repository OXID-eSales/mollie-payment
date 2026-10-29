<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\OxidClassicSettingsUrl;
use PHPUnit\Framework\TestCase;

final class OxidClassicSettingsUrlTest extends TestCase
{
    public function testUrl_PointsAtTheModuleConfigPageOfTheShop(): void
    {
        self::assertSame(
            'https://shop.example.com/admin/index.php?cl=module_config&oxid=oe_payments_mollie&shp=3',
            $this->provider('https://shop.example.com/', 'admin', 3)->url()
        );
    }

    public function testUrl_RespectsAConfiguredAdminDirectory(): void
    {
        self::assertStringStartsWith(
            'https://shop.example.com/backoffice/index.php?',
            $this->provider('https://shop.example.com/', 'backoffice', 1)->url()
        );
    }

    public function testUrl_AddsTheMissingSlashAfterTheShopUrl(): void
    {
        self::assertStringStartsWith(
            'https://shop.example.com/admin/index.php?',
            $this->provider('https://shop.example.com', 'admin', 1)->url()
        );
    }

    private function provider(string $shopUrl, string $adminDir, int $shopId): OxidClassicSettingsUrl
    {
        return new class ($shopUrl, $adminDir, $shopId) extends OxidClassicSettingsUrl {
            public function __construct(
                private readonly string $stubbedShopUrl,
                private readonly string $stubbedAdminDir,
                private readonly int $stubbedShopId,
            ) {
            }

            protected function adminShopBaseUrl(): string
            {
                return $this->stubbedShopUrl;
            }

            protected function adminDirectory(): string
            {
                return $this->stubbedAdminDir;
            }

            protected function shopId(): int
            {
                return $this->stubbedShopId;
            }
        };
    }
}
