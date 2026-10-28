<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Mollie\Admin\ReleaseRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, float, ?float, bool}>
     */
    public static function releasability(): iterable
    {
        yield 'hold, 40 captured, 60 left' => [true, 60.0, 40.0, true];
        yield 'hold, nothing captured: that is a cancel, not a release' => [true, 100.0, null, false];
        yield 'hold, fully captured: nothing left to release' => [true, 0.0, 100.0, false];
        yield 'not an authorized hold any more' => [false, 60.0, 40.0, false];
    }

    #[DataProvider('releasability')]
    public function testIsReleasable(bool $hold, float $captureBound, ?float $captured, bool $expected): void
    {
        $bounds = $this->createStub(AdminActionBoundsInterface::class);
        $bounds->method('isAuthorizedHold')->willReturn($hold);
        $bounds->method('captureBound')->willReturn($captureBound);

        self::assertSame($expected, (new ReleaseRule($bounds))->isReleasable($this->contract(100.0, $captured)));
    }

    public function testReleasableAmount_IsTheRemainderWhenReleasableAndZeroOtherwise(): void
    {
        $bounds = $this->createStub(AdminActionBoundsInterface::class);
        $bounds->method('isAuthorizedHold')->willReturn(true);
        $bounds->method('captureBound')->willReturn(60.0);
        $rule = new ReleaseRule($bounds);

        self::assertSame(60.0, $rule->releasableAmount($this->contract(100.0, 40.0)));
        self::assertSame(0.0, $rule->releasableAmount($this->contract(100.0, null)), 'nothing captured: cancel, not release');
    }

    public function testReleasedAmount_IsWhatWentBackToTheCustomer(): void
    {
        $rule = new ReleaseRule($this->createStub(AdminActionBoundsInterface::class));

        self::assertSame(60.0, $rule->releasedAmount($this->contract(100.0, 40.0)));
        self::assertSame(0.0, $rule->releasedAmount($this->contract(100.0, 100.0)));
        self::assertSame(100.0, $rule->releasedAmount($this->contract(100.0, null)));
    }

    public function testIsReleased_OnlyAFulfilledOrderThatKeptLessThanItAuthorized(): void
    {
        $rule = new ReleaseRule($this->createStub(AdminActionBoundsInterface::class));

        self::assertTrue($rule->isReleased($this->contract(100.0, 40.0, fulfilled: true)));
        self::assertFalse($rule->isReleased($this->contract(100.0, 40.0, fulfilled: false)));
        self::assertFalse($rule->isReleased($this->contract(100.0, 100.0, fulfilled: true)));
        self::assertFalse($rule->isReleased($this->contract(100.0, null, fulfilled: true)));
    }

    private function contract(float $amount, ?float $captured, bool $fulfilled = false): PaymentContractInterface
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getAmount')->willReturn($amount);
        $contract->method('getCapturedAmount')->willReturn($captured);
        $contract->method('getState')->willReturn($fulfilled ? ContractState::fulfilled() : ContractState::committed());

        return $contract;
    }
}
