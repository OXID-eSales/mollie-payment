<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Adapter\Dto;

use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieCaptureDto::class)]
final class MollieCaptureDtoTest extends TestCase
{
    public function testFromArray_MapsCaptureIdAmountAndStatus(): void
    {
        $dto = MollieCaptureDto::fromArray([
            'id' => 'cpt_123',
            'paymentId' => 'tr_abc',
            'amount' => ['currency' => 'EUR', 'value' => '30.00'],
            'status' => 'succeeded',
        ]);

        self::assertSame('cpt_123', $dto->id);
        self::assertSame('tr_abc', $dto->paymentId);
        self::assertSame(30.0, $dto->amount->value);
        self::assertSame('succeeded', $dto->status);
    }

    public function testFromArray_MapsCreatedAt(): void
    {
        $dto = MollieCaptureDto::fromArray([
            'id' => 'cpt_ts',
            'paymentId' => 'tr_abc',
            'amount' => ['currency' => 'EUR', 'value' => '30.00'],
            'status' => 'succeeded',
            'createdAt' => '2026-07-08T12:00:00+00:00',
        ]);

        self::assertSame('2026-07-08T12:00:00+00:00', $dto->createdAt);
    }

    public function testFromArray_CreatedAtDefaultsToNull(): void
    {
        $dto = MollieCaptureDto::fromArray(['id' => 'cpt_x', 'paymentId' => 'tr_x']);

        self::assertNull($dto->createdAt);
    }
}
