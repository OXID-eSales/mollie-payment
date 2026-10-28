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
 * payment-base's admin payment API (refund, capture, void) in front of the real Mollie panel provider: the reasons and
 * limits the provider declares, its live bounds replacing the local ones, and its typed refusals - with the real
 * database and the real payment-base services, only Mollie faked.
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

        $result = $this->operations()->refund(self::ORDER_ID, 10.0, 'duplicate', 'pay5-key-options');
        self::assertNull($result->refusal(), (string) $result->refusal()?->code . ' ' . $result->refusal()?->message);
        $options = $result->payment()->actionOptions();

        self::assertSame(['requested_by_customer', 'duplicate', 'fraudulent'], $options->refundReasons);
        self::assertSame(['requested_by_customer', 'duplicate', 'fraudulent', 'abandoned'], $options->voidReasons);
        self::assertTrue($options->refundDescription);
        self::assertSame(140, $options->reasonMaxLength);
    }

    public function testRefund_IsBoundedByTheLiveRemainderNotByTheShopsOwnRecord(): void
    {
        $this->saveCommittedMollieContract(self::ORDER_ID, 100.0, 100.0, 'tr_pay5_live');
        // 30.00 was refunded in the Mollie dashboard: the shop does not know, Mollie does.
        $this->scriptLivePayment(['id' => 'tr_pay5_live', 'status' => 'paid', 'amount' => ['currency' => 'EUR', 'value' => '100.00'], 'amountRemaining' => '70.00']);

        $tooMuch = $this->operations()->refund(self::ORDER_ID, 80.0, null, 'pay5-key-live-1');
        $fine = $this->operations()->refund(self::ORDER_ID, 70.0, 'requested_by_customer', 'pay5-key-live-2');

        self::assertSame('exceeds_refundable', $tooMuch->refusal()?->code);
        self::assertNull($fine->refusal());
        self::assertSame(['refund:70'], $this->actionsRun, 'only the permitted refund reached the provider');
    }

    public function testRefund_AReasonOutsideTheDeclaredList_IsRefusedBeforeMollieIsAsked(): void
    {
        $this->saveCommittedMollieContract(self::ORDER_ID, 100.0, 100.0, 'tr_pay5_reason');
        $this->scriptLivePayment(['id' => 'tr_pay5_reason', 'status' => 'paid', 'amount' => ['currency' => 'EUR', 'value' => '100.00']]);

        $refusal = $this->operations()->refund(self::ORDER_ID, 10.0, 'because', 'pay5-key-reason')->refusal();

        self::assertSame('reason_invalid', $refusal?->code);
        self::assertSame([], $this->actionsRun);
    }

    public function testRefund_AFreeTextTheRulesRefuse_IsAProviderRefusalWithTheSentenceTheClassicTabShows(): void
    {
        $this->saveCommittedMollieContract(self::ORDER_ID, 100.0, 100.0, 'tr_pay5_text');
        $this->scriptLivePayment(['id' => 'tr_pay5_text', 'status' => 'paid', 'amount' => ['currency' => 'EUR', 'value' => '100.00']]);

        $refusal = $this->operations()->refund(self::ORDER_ID, 10.0, null, 'pay5-key-text', 'chargeback <script>')->refusal();

        self::assertSame('provider_refused', $refusal?->code);
        self::assertNotSame('', $refusal->message);
        self::assertSame([], $this->actionsRun, 'Mollie was not asked');
    }

    public function testBounds_MollieCannotBeRead_NothingIsOfferedAndAWriteIsRefused(): void
    {
        $this->saveCommittedMollieContract(self::ORDER_ID, 100.0, 100.0, 'tr_pay5_down');
        $this->mollieDown = true;

        $refusal = $this->operations()->refund(self::ORDER_ID, 10.0, null, 'pay5-key-down')->refusal();

        self::assertSame('provider_unavailable', $refusal?->code);
        self::assertSame([], $this->actionsRun);
    }

    public function testCapture_AMultiCapturePaymentOffersWhatIsLeftOfTheAuthorization(): void
    {
        $this->saveCommittedMollieContract(self::ORDER_ID, 100.0, 40.0, 'tr_pay5_multi', fulfilled: false);
        $this->scriptLivePayment(['id' => 'tr_pay5_multi', 'status' => 'authorized', 'amount' => ['currency' => 'EUR', 'value' => '100.00'], 'amountCaptured' => '40.00']);

        $tooMuch = $this->operations()->capture(self::ORDER_ID, 70.0, 'pay5-key-multi-1');
        $fine = $this->operations()->capture(self::ORDER_ID, 60.0, 'pay5-key-multi-2');

        self::assertSame('exceeds_capturable', $tooMuch->refusal()?->code);
        self::assertNull($fine->refusal(), (string) $fine->refusal()?->message);
        self::assertSame(['capture:60'], $this->actionsRun);
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
