<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieAdminRefusal;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigChange;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigChangeValidator;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigModifier;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\ConfigFixtures;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\CountingStepUp;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\InMemoryMollieSettings;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\RecordingMollieAudit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MollieConfigModifierTest extends TestCase
{
    private InMemoryMollieSettings $settings;
    private CountingStepUp $stepUp;
    private RecordingMollieAudit $audit;

    protected function setUp(): void
    {
        $this->settings = new InMemoryMollieSettings(ConfigFixtures::snapshot());
        $this->stepUp = new CountingStepUp();
        $this->audit = new RecordingMollieAudit();
    }

    public function testModify_AHarmlessField_IsSavedWithoutAStepUp(): void
    {
        $result = $this->modifier()->modify($this->change(captureMode: 'manual'));

        self::assertNull($result->refusal());
        self::assertSame('manual', $result->config()->captureMode);
        self::assertSame('manual', $this->settings->snapshot()->captureMode);
        self::assertSame(0, $this->stepUp->required);
    }

    public function testModify_AFieldThatIsNotGiven_StaysAsItWas(): void
    {
        $this->modifier()->modify($this->change(logLevel: 'debug'));

        $saved = $this->settings->snapshot();
        self::assertSame('automatic', $saved->captureMode);
        self::assertSame('pfl_AbC123', $saved->profileId);
        self::assertSame('debug', $saved->logLevel);
    }

    public function testModify_AnEmptyKey_LeavesTheStoredKeyAlone(): void
    {
        $this->modifier()->modify($this->change(testKey: '', captureMode: 'manual'));

        self::assertSame(ConfigFixtures::TEST_KEY, $this->settings->keys['test']);
        self::assertTrue($this->settings->snapshot()->testKeySet);
        self::assertSame(0, $this->stepUp->required, 'an empty key writes nothing, so it needs no step-up');
    }

    public function testModify_ANewKey_IsStoredAfterAStepUpAndNeverReturned(): void
    {
        $newKey = 'test_ZyXwVuTsRqPoNmLkJiHgFeDcBa9876';

        $result = $this->modifier()->modify($this->change(testKey: $newKey));

        self::assertNull($result->refusal());
        self::assertSame($newKey, $this->settings->keys['test']);
        self::assertStringNotContainsString($newKey, (string) json_encode($result->config()));
        self::assertSame(1, $this->stepUp->required);
    }

    public function testModify_AKeyWithTheOtherModesPrefix_IsRefusedAndNothingIsSaved(): void
    {
        $result = $this->modifier()->modify($this->change(testKey: ConfigFixtures::LIVE_KEY, captureMode: 'manual'));

        self::assertSame('invalid_prefix', $result->refusal()?->code);
        self::assertSame('testKey', $result->refusal()->field);
        self::assertSame(0, $this->settings->writes);
        self::assertSame('automatic', $this->settings->snapshot()->captureMode, 'the other fields of the call are not saved either');
        self::assertStringNotContainsString(ConfigFixtures::LIVE_KEY, $result->refusal()->message);
    }

    public function testModify_SwitchingToLive_NeedsAStepUpBeforeAnythingIsChanged(): void
    {
        $this->stepUp = new CountingStepUp(proven: false);
        $this->settings = new InMemoryMollieSettings(ConfigFixtures::snapshot(['liveKeySet' => true]));

        try {
            $this->modifier()->modify($this->change(mode: 'live'));
            self::fail('the step-up must stop the switch');
        } catch (RuntimeException $stepUpRequired) {
            self::assertSame('step_up_required', $stepUpRequired->getMessage());
        }

        self::assertSame(0, $this->settings->writes);
        self::assertSame('test', $this->settings->snapshot()->mode);
        self::assertSame([['operation' => 'adminMollieConfigModify', 'fields' => ['mode'], 'outcome' => RuntimeException::class]], $this->audit->rows);
    }

    public function testModify_SwitchingToLive_WithAStepUpSwitches(): void
    {
        $this->settings = new InMemoryMollieSettings(ConfigFixtures::snapshot(['liveKeySet' => true]));

        $result = $this->modifier()->modify($this->change(mode: 'live'));

        self::assertNull($result->refusal());
        self::assertSame('live', $this->settings->snapshot()->mode);
        self::assertSame(1, $this->stepUp->required);
    }

    public function testModify_SwitchingToLiveWithTheLiveKeyInTheSameCall_IsAccepted(): void
    {
        $result = $this->modifier()->modify($this->change(mode: 'live', liveKey: ConfigFixtures::LIVE_KEY));

        self::assertNull($result->refusal());
        self::assertTrue($this->settings->snapshot()->liveKeySet);
    }

    public function testModify_SwitchingToLiveWithoutALiveKey_IsRefused(): void
    {
        $result = $this->modifier()->modify($this->change(mode: 'live'));

        self::assertSame('key_missing', $result->refusal()?->code);
        self::assertSame('mode', $result->refusal()->field);
        self::assertSame(0, $this->settings->writes);
    }

    public function testModify_StayingInLive_NeedsNoStepUpForAHarmlessField(): void
    {
        $this->settings = new InMemoryMollieSettings(ConfigFixtures::snapshot(['mode' => 'live', 'liveKeySet' => true]));

        $this->modifier()->modify($this->change(mode: 'live', multiCapture: true));

        self::assertSame(0, $this->stepUp->required);
        self::assertTrue($this->settings->snapshot()->multiCapture);
    }

    public function testModify_AStaleVersion_IsAConflictAndNothingIsSaved(): void
    {
        $result = $this->modifier()->modify(new MollieConfigChange(expectedVersion: 'stale', captureMode: 'manual'));

        self::assertSame(MollieAdminRefusal::CONFLICT, $result->refusal()?->kind);
        self::assertSame('stale', $result->refusal()->expectedVersion);
        self::assertSame($this->settings->snapshot()->version(), $result->refusal()->actualVersion);
        self::assertSame(0, $this->settings->writes);
    }

    public function testModify_AnInvalidInput_IsRefusedAndNothingIsSaved(): void
    {
        $result = $this->modifier()->modify($this->change(captureMode: 'later'));

        self::assertSame('captureMode', $result->refusal()?->field);
        self::assertSame(0, $this->settings->writes);
    }

    public function testModify_EveryCallIsAuditedOnceWithFieldNamesOnly(): void
    {
        $this->modifier()->modify($this->change(captureMode: 'manual', testKey: 'test_ZyXwVuTsRqPoNmLkJiHgFeDcBa9876'));
        $this->modifier()->modify($this->change(captureMode: 'later'));

        self::assertSame(
            [
                ['operation' => 'adminMollieConfigModify', 'fields' => ['captureMode', 'testKey'], 'outcome' => 'ok'],
                ['operation' => 'adminMollieConfigModify', 'fields' => ['captureMode'], 'outcome' => 'not_allowed'],
            ],
            $this->audit->rows,
        );
        self::assertStringNotContainsString('ZyXwVuTs', (string) json_encode($this->audit->rows));
    }

    private function modifier(): MollieConfigModifier
    {
        return new MollieConfigModifier($this->settings, new MollieConfigChangeValidator(), $this->stepUp, $this->audit);
    }

    private function change(
        ?string $mode = null,
        ?string $captureMode = null,
        ?bool $multiCapture = null,
        ?string $logLevel = null,
        ?string $testKey = null,
        ?string $liveKey = null,
    ): MollieConfigChange {
        return new MollieConfigChange(
            expectedVersion: $this->settings->snapshot()->version(),
            mode: $mode,
            captureMode: $captureMode,
            multiCapture: $multiCapture,
            logLevel: $logLevel,
            testKey: $testKey,
            liveKey: $liveKey,
        );
    }
}
