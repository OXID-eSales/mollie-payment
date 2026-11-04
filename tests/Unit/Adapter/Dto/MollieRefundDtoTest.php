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
    /**
     * VR-21 F3: payment-base restocks only for a refund the PSP says succeeded; Mollie's `refunded`
     * is that status, every in-flight one (queued, pending, processing) is not.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function testHasSucceeded_OnlyForRefunded(string $status, bool $expected): void
    {
        $dto = new MollieRefundDto('re_1', 'tr_1', \OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto::fromComponents('EUR', 5.0), $status);

        self::assertSame($expected, $dto->hasSucceeded());
    }

    /** @return array<string, array{string, bool}> */
    public static function statuses(): array
    {
        return [
            'refunded' => ['refunded', true],
            'pending' => ['pending', false],
            'queued' => ['queued', false],
            'processing' => ['processing', false],
            'failed' => ['failed', false],
            'canceled' => ['canceled', false],
        ];
    }
}
