<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter\Dto;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieRefundDto::class)]
final class MollieRefundDtoTest extends TestCase
{
    public function testFromArray_MapsRefundIdAndAmount(): void
    {
        $dto = MollieRefundDto::fromArray([
            'id' => 're_123',
            'paymentId' => 'tr_abc',
            'amount' => ['currency' => 'EUR', 'value' => '5.00'],
            'status' => 'pending',
        ]);

        self::assertSame('re_123', $dto->id);
        self::assertSame('tr_abc', $dto->paymentId);
        self::assertSame(5.0, $dto->amount->value);
        self::assertSame('pending', $dto->status);
    }

    public function testFromArray_MapsCreatedAt(): void
    {
        $dto = MollieRefundDto::fromArray([
            'id' => 're_ts',
            'paymentId' => 'tr_abc',
            'amount' => ['currency' => 'EUR', 'value' => '5.00'],
            'status' => 'refunded',
            'createdAt' => '2026-07-08T11:00:00+00:00',
        ]);

        self::assertSame('2026-07-08T11:00:00+00:00', $dto->createdAt);
    }

    public function testFromArray_CreatedAtDefaultsToNull(): void
    {
        $dto = MollieRefundDto::fromArray(['id' => 're_x', 'paymentId' => 'tr_x']);

        self::assertNull($dto->createdAt);
    }
}
