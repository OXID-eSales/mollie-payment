<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\GraphQL\Admin;

// CI runs this suite with the shop bootstrap, which does not autoload the module's Tests\ namespace.
require_once __DIR__ . '/../../Support/MollieAdminApiHarness.php';

use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\Payments\Mollie\Tests\Integration\Support\MollieAdminApiHarness;
use PHPUnit\Framework\Attributes\Group;

/**
 * payment-base's admin payment API in front of the real Mollie panel provider: the reasons and limits the provider
 * declares, and the Mollie-only release with its step-up, idempotency and typed refusals - with the real database and
 * the real payment-base services, only Mollie faked. Capture, refund and void run in payment-base's money runner
 * (VR-22 A3) through Mollie's gateways, which have unit tests.
 */
#[Group('integration')]
final class MollieAdminPaymentOperationsIntegrationTest extends IntegrationTestCase
{
    use MollieAdminApiHarness;

    private const ORDER_ID = 'pay5-op-order';

    public function setUp(): void
    {
        parent::setUp();
        $this->connection()->executeStatement('INSERT INTO oxorder (OXID, OXSHOPID) VALUES (?, 1)', [self::ORDER_ID]);
    }

    public function testTheContractCarriesMolliesReasonsAndTextLimit(): void
    {
        $this->saveCommittedMollieContract(self::ORDER_ID, 100.0, 100.0, 'tr_pay5_options');
        $this->scriptLivePayment(['id' => 'tr_pay5_options', 'status' => 'paid', 'amount' => ['currency' => 'EUR', 'value' => '100.00']]);

        $options = $this->detailsReader()->findByOrderId(self::ORDER_ID)?->actionOptions();

        self::assertNotNull($options);
        self::assertSame(['requested_by_customer', 'duplicate', 'fraudulent'], $options->refundReasons);
        self::assertSame(['requested_by_customer', 'duplicate', 'fraudulent', 'abandoned'], $options->voidReasons);
        self::assertTrue($options->refundDescription);
        self::assertSame(140, $options->reasonMaxLength);
    }

    public function testRelease_GivesTheRemainderBackOnceAndARetryWithTheSameKeyDoesNotCallMollieAgain(): void
    {
        $this->saveCommittedMollieContract(self::ORDER_ID, 100.0, 40.0, 'tr_pay5_rel', fulfilled: false);
        $this->scriptLivePayment(['id' => 'tr_pay5_rel', 'status' => 'authorized', 'amount' => ['currency' => 'EUR', 'value' => '100.00'], 'amountCaptured' => '40.00']);

        $first = $this->releaseOperation()->release(self::ORDER_ID, 'customer keeps 40', 'pay5-rel-key-1');
        $retry = $this->releaseOperation()->release(self::ORDER_ID, 'customer keeps 40', 'pay5-rel-key-1');

        self::assertNull($first->refusal(), (string) $first->refusal()?->message);
        self::assertSame('fulfilled', $first->payment()->state);
        self::assertNull($retry->refusal());
        self::assertSame(['release:customer keeps 40'], $this->actionsRun, 'the retry does not reach Mollie again');
        self::assertSame(2, $this->stepUps);
    }

    public function testRelease_WithNothingCapturedYet_IsRefusedByTheClassicRuleAndNothingIsCancelled(): void
    {
        $this->saveCommittedMollieContract(self::ORDER_ID, 100.0, null, 'tr_pay5_rel2', fulfilled: false);
        $this->scriptLivePayment(['id' => 'tr_pay5_rel2', 'status' => 'authorized', 'amount' => ['currency' => 'EUR', 'value' => '100.00']]);

        $refusal = $this->releaseOperation()->release(self::ORDER_ID, null, 'pay5-rel-key-2')->refusal();

        self::assertSame('provider_refused', $refusal?->code);
        self::assertStringContainsString('MOLLIE_ADMIN_RELEASE_NOT_POSSIBLE', (string) $refusal->message);
        self::assertSame([], $this->actionsRun);
    }

    public function testRelease_MollieRefusesTheRelease_IsAProviderRefusalAndTheKeyCanBeRetried(): void
    {
        $this->saveCommittedMollieContract(self::ORDER_ID, 100.0, 40.0, 'tr_pay5_rel3', fulfilled: false);
        $this->scriptLivePayment(['id' => 'tr_pay5_rel3', 'status' => 'authorized', 'amount' => ['currency' => 'EUR', 'value' => '100.00'], 'amountCaptured' => '40.00']);
        $this->releaseSucceeds = false;

        $refusal = $this->releaseOperation()->release(self::ORDER_ID, null, 'pay5-rel-key-3')->refusal();
        $this->releaseSucceeds = true;
        $retry = $this->releaseOperation()->release(self::ORDER_ID, null, 'pay5-rel-key-3');

        self::assertSame('provider_refused', $refusal?->code);
        self::assertNull($retry->refusal(), 'a refused call releases its key');
    }
}
