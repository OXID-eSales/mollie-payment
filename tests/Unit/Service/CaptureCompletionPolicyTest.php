<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\Payments\Mollie\Service\CaptureCompletion;
use OxidEsales\Payments\Mollie\Service\CaptureCompletionPolicy;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * MOL-32 S2 — the single place that decides whether a capture completes the order, so the
 * single-vs-multi-capture branch lives behind one injected policy rather than scattered
 * `if ($config->isMultiCaptureEnabled())` reads across the services.
 */
#[CoversClass(CaptureCompletionPolicy::class)]
final class CaptureCompletionPolicyTest extends TestCase
{
    public function testSingleCaptureAccount_FirstPartialCaptureIsFinal(): void
    {
        $policy = $this->policy(multiCapture: false);

        // Even with 60.00 still unspent on the authorization, a single-capture account treats the
        // capture as final — Mollie releases the remainder (MOL-31 option B).
        self::assertSame(CaptureCompletion::FullyCaptured, $policy->classify(remainingToCapture: 60.0));
        self::assertSame(CaptureCompletion::FullyCaptured, $policy->classify(remainingToCapture: 0.0));
    }

    public function testMultiCaptureAccount_RemainderKeepsTheOrderOpen(): void
    {
        $policy = $this->policy(multiCapture: true);

        self::assertSame(CaptureCompletion::PartialCaptureRemains, $policy->classify(remainingToCapture: 60.0));
    }

    public function testMultiCaptureAccount_NothingLeftCompletesTheCapture(): void
    {
        $policy = $this->policy(multiCapture: true);

        self::assertSame(CaptureCompletion::FullyCaptured, $policy->classify(remainingToCapture: 0.0));
    }

    public function testMultiCaptureAccount_FloatNoiseBelowACentCountsAsFullyCaptured(): void
    {
        $policy = $this->policy(multiCapture: true);

        self::assertSame(CaptureCompletion::FullyCaptured, $policy->classify(remainingToCapture: 0.1 + 0.2 - 0.3));
    }

    private function policy(bool $multiCapture): CaptureCompletionPolicy
    {
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);
        $config->method('isMultiCaptureEnabled')->willReturn($multiCapture);

        return new CaptureCompletionPolicy($config);
    }
}
