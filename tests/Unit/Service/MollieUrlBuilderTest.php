<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\MollieUrlBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieUrlBuilder::class)]
final class MollieUrlBuilderTest extends TestCase
{
    private MollieUrlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new MollieUrlBuilder();
    }

    public function testPaymentUrl_PointsAtTheDashboardPaymentPage(): void
    {
        self::assertSame(
            'https://my.mollie.com/dashboard/payments/tr_abc',
            $this->builder->paymentUrl('tr_abc'),
        );
    }

    public function testPaymentUrl_NeverCarriesATestModeSegment(): void
    {
        // Mollie's dashboard no longer encodes the mode in the path: the same
        // /dashboard/payments/{id} page serves test and live payments, so the
        // builder must not read the module mode at all.
        self::assertStringNotContainsString('test-mode', $this->builder->paymentUrl('tr_abc'));
    }

    public function testPaymentUrl_EncodesThePaymentId(): void
    {
        self::assertSame(
            'https://my.mollie.com/dashboard/payments/tr%2Fabc',
            $this->builder->paymentUrl('tr/abc'),
        );
    }
}
