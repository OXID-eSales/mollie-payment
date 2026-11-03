<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminAuditInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\IdempotencyClaim;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OperationIdempotencyInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReaderInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\RequestShopOrdersInterface;
use OxidEsales\Payments\Mollie\Admin\ReleaseActionInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieReleaseOperation;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\CountingStepUp;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class MollieReleaseOperationTest extends TestCase
{
    /** @var list<PaymentContractDetails|null> */
    private array $reads;
    /** @var list<array{string, ?string}> */
    private array $providerCalls = [];
    private ?Throwable $releaseFailure = null;
    private string $claimOutcome = IdempotencyClaim::FRESH;
    /** @var list<string> */
    private array $idempotencyLog = [];
    /** @var list<array{string, string, list<string>, ?float}> */
    private array $auditRows = [];
    private bool $inShop = true;
    private CountingStepUp $stepUp;

    protected function setUp(): void
    {
        $this->reads = [$this->payment('committed', 40.0), $this->payment('fulfilled', 40.0)];
        $this->stepUp = new CountingStepUp();
    }

    public function testRelease_RunsTheProvidersReleaseActionWithTheReasonAndReadsTheResultBack(): void
    {
        $result = $this->operation()->release('o1', '  customer keeps 40  ', 'release-key-1');

        self::assertNull($result->refusal());
        self::assertSame('fulfilled', $result->payment()->state);
        self::assertSame([['o1', 'customer keeps 40']], $this->providerCalls);
        self::assertSame(1, $this->stepUp->required);
        self::assertSame(['claim:admin_mollie_release', 'complete'], $this->idempotencyLog);
    }

    public function testRelease_IsAuditedOnceWithTheFieldNameAndNeverTheText(): void
    {
        $this->operation()->release('o1', 'secret reason text', null);

        self::assertSame([['adminMolliePaymentRelease', 'o1', ['reason'], null]], $this->auditRows);
    }

    public function testRelease_AStepUpThatIsMissing_StopsEverythingButIsStillAudited(): void
    {
        $this->stepUp = new CountingStepUp(proven: false);

        try {
            $this->operation()->release('o1', null, null);
            self::fail('the step-up must stop the release');
        } catch (RuntimeException) {
            self::assertSame([], $this->providerCalls);
            self::assertCount(1, $this->auditRows);
        }
    }

    public function testRelease_ARetryWithTheSameKey_ReportsTheEarlierSuccessWithoutCallingMollie(): void
    {
        $this->claimOutcome = IdempotencyClaim::COMPLETED;

        $result = $this->operation()->release('o1', null, 'release-key-1');

        self::assertNull($result->refusal());
        self::assertSame([], $this->providerCalls);
    }

    public function testRelease_TheSameCallStillRunning_IsInProgress(): void
    {
        $this->claimOutcome = IdempotencyClaim::IN_FLIGHT;

        self::assertSame('in_progress', $this->operation()->release('o1', null, 'release-key-1')->refusal()?->code);
        self::assertSame([], $this->providerCalls);
    }

    public function testRelease_TheProviderRefuses_TheSentenceIsTheErrorAndTheKeyIsReleased(): void
    {
        $this->releaseFailure = new AdminActionRefusedException('not_releasable', 'The remaining authorization cannot be released');

        $refusal = $this->operation()->release('o1', null, 'release-key-1')->refusal();

        self::assertSame('provider_refused', $refusal?->code);
        self::assertSame('The remaining authorization cannot be released', $refusal->message);
        self::assertSame(['claim:admin_mollie_release', 'release'], $this->idempotencyLog);
    }

    public function testRelease_TheProviderThrows_IsProviderFailed(): void
    {
        $this->releaseFailure = new RuntimeException('Mollie is down');

        self::assertSame('provider_failed', $this->operation()->release('o1', null, 'release-key-1')->refusal()?->code);
    }

    public function testRelease_TheProviderThrows_NothingOfItsMessageLeaksAndTheKeyIsReleased(): void
    {
        $this->releaseFailure = new RuntimeException('secret detail');

        $refusal = $this->operation()->release('o1', null, 'release-key-1')->refusal();

        self::assertStringNotContainsString('secret', (string) $refusal?->message);
        self::assertSame(['claim:admin_mollie_release', 'release'], $this->idempotencyLog);
    }

    public function testRelease_NothingMovedAfterTheProviderRan_IsNotConfirmed(): void
    {
        $this->reads = [$this->payment('committed', 40.0), $this->payment('committed', 40.0)];

        $refusal = $this->operation()->release('o1', null, 'release-key-1')->refusal();

        self::assertSame('not_confirmed', $refusal?->code);
        self::assertSame(['claim:admin_mollie_release', 'release'], $this->idempotencyLog);
    }

    public function testRelease_AnOrderOfAnotherShopOrWithoutAPayment_IsNotFound(): void
    {
        $this->inShop = false;
        self::assertSame('not_found', $this->operation()->release('o1', null, null)->refusal()?->kind);

        $this->inShop = true;
        $this->reads = [null];
        self::assertSame('not_found', $this->operation()->release('o1', null, null)->refusal()?->kind);
        self::assertSame([], $this->providerCalls);
    }

    public function testRelease_AContractOfAnotherProvider_IsNotReleasable(): void
    {
        $this->reads = [$this->payment('committed', 40.0, 'stripe')];

        self::assertSame('not_releasable', $this->operation()->release('o1', null, null)->refusal()?->code);
        self::assertSame([], $this->providerCalls);
    }

    public function testRelease_InvalidInput_IsRefusedBeforeAnythingIsTouched(): void
    {
        self::assertSame('invalid_key', $this->operation()->release('o1', null, 'short')->refusal()?->code);
        self::assertSame('too_long', $this->operation()->release('o1', str_repeat('a', 141), null)->refusal()?->code);
        self::assertSame('invalid_characters', $this->operation()->release('o1', "a\x07b", null)->refusal()?->code);
        self::assertSame([], $this->providerCalls);
        self::assertSame([], $this->idempotencyLog);
    }

    private function payment(string $state, float $captured, string $provider = 'mollie'): PaymentContractDetails
    {
        return new PaymentContractDetails('c1', $provider, $state, 100.0, $captured, 0.0, 'EUR', []);
    }

    private function operation(): MollieReleaseOperation
    {
        $reads = &$this->reads;
        $details = $this->createStub(PaymentContractDetailsReaderInterface::class);
        $details->method('findByOrderId')->willReturnCallback(static function () use (&$reads): ?PaymentContractDetails {
            return count($reads) > 1 ? array_shift($reads) : $reads[0];
        });
        $release = $this->createStub(ReleaseActionInterface::class);
        $release->method('run')->willReturnCallback(function (string $orderId, ?string $reason): void {
            $this->providerCalls[] = [$orderId, $reason];
            if ($this->releaseFailure !== null) {
                throw $this->releaseFailure;
            }
        });
        $idempotency = $this->createStub(OperationIdempotencyInterface::class);
        $idempotency->method('claim')->willReturnCallback(function (string $operation, string $orderId): IdempotencyClaim {
            $this->idempotencyLog[] = 'claim:' . $operation;

            return new IdempotencyClaim($this->claimOutcome, 'k', $operation, $orderId);
        });
        $idempotency->method('complete')->willReturnCallback(function (): void {
            $this->idempotencyLog[] = 'complete';
        });
        $idempotency->method('release')->willReturnCallback(function (): void {
            $this->idempotencyLog[] = 'release';
        });
        $audit = $this->createStub(AdminAuditInterface::class);
        $audit->method('audited')->willReturnCallback(function (string $name, string $orderId, array $fields, ?float $amount, \Closure $action): PaymentOperationResult {
            $this->auditRows[] = [$name, $orderId, $fields, $amount];

            return $action();
        });
        $shop = $this->createStub(RequestShopOrdersInterface::class);
        $shop->method('contains')->willReturnCallback(fn (): bool => $this->inShop);

        return new MollieReleaseOperation($details, $release, $idempotency, $this->stepUp, $audit, $shop, $this->createStub(LoggerInterface::class));
    }
}
