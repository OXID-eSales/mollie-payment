<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigChange;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConfigChangeValidator;
use OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Support\ConfigFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MollieConfigChangeValidatorTest extends TestCase
{
    public function testValidate_NothingGiven_IsFine(): void
    {
        self::assertNull((new MollieConfigChangeValidator())->validate(new MollieConfigChange('v')));
    }

    public function testValidate_EveryAcceptableValue_IsFine(): void
    {
        $change = new MollieConfigChange(
            'v',
            mode: 'live',
            captureMode: 'manual',
            multiCapture: true,
            profileId: 'pfl_aBc123',
            webhookUrlOverride: 'https://hooks.example.com/mollie?x=1',
            logLevel: 'debug',
            testKey: ConfigFixtures::TEST_KEY,
            liveKey: ConfigFixtures::LIVE_KEY,
        );

        self::assertNull((new MollieConfigChangeValidator())->validate($change));
    }

    public function testValidate_EmptyProfileIdAndEmptyWebhookOverride_AreAllowedTheyRemoveTheValue(): void
    {
        $change = new MollieConfigChange('v', profileId: '', webhookUrlOverride: '');

        self::assertNull((new MollieConfigChangeValidator())->validate($change));
    }

    /**
     * @return iterable<string, array{MollieConfigChange, string, string}>
     */
    public static function refusedChanges(): iterable
    {
        yield 'mode' => [new MollieConfigChange('v', mode: 'sandbox'), 'mode', 'not_allowed'];
        yield 'capture mode' => [new MollieConfigChange('v', captureMode: 'later'), 'captureMode', 'not_allowed'];
        yield 'log level' => [new MollieConfigChange('v', logLevel: 'trace'), 'logLevel', 'not_allowed'];
        yield 'test key with the live prefix' => [new MollieConfigChange('v', testKey: ConfigFixtures::LIVE_KEY), 'testKey', 'invalid_prefix'];
        yield 'live key with the test prefix' => [new MollieConfigChange('v', liveKey: ConfigFixtures::TEST_KEY), 'liveKey', 'invalid_prefix'];
        yield 'key without any prefix' => [new MollieConfigChange('v', testKey: 'AbCdEfGhIjKlMnOpQrStUvWxYz012345'), 'testKey', 'invalid_prefix'];
        yield 'too short key' => [new MollieConfigChange('v', testKey: 'test_short'), 'testKey', 'invalid_format'];
        yield 'key with a space' => [new MollieConfigChange('v', liveKey: 'live_AbCdEfGhIjKlMnOpQrStUvWx Yz4567'), 'liveKey', 'invalid_format'];
        yield 'profile id without pfl_' => [new MollieConfigChange('v', profileId: 'abc123'), 'profileId', 'invalid_format'];
        yield 'profile id with markup' => [new MollieConfigChange('v', profileId: 'pfl_<b>'), 'profileId', 'invalid_format'];
        yield 'webhook url without a scheme' => [new MollieConfigChange('v', webhookUrlOverride: 'hooks.example.com/mollie'), 'webhookUrlOverride', 'invalid_format'];
        yield 'webhook url with another scheme' => [new MollieConfigChange('v', webhookUrlOverride: 'javascript://x'), 'webhookUrlOverride', 'invalid_format'];
        yield 'webhook url with a space' => [new MollieConfigChange('v', webhookUrlOverride: 'https://hooks.example.com/a b'), 'webhookUrlOverride', 'invalid_format'];
        yield 'webhook url too long' => [new MollieConfigChange('v', webhookUrlOverride: 'https://example.com/' . str_repeat('a', 300)), 'webhookUrlOverride', 'invalid_format'];
    }

    #[DataProvider('refusedChanges')]
    public function testValidate_AnUnacceptableValue_IsRefusedWithFieldAndCode(MollieConfigChange $change, string $field, string $code): void
    {
        $refusal = (new MollieConfigChangeValidator())->validate($change);

        self::assertSame($field, $refusal?->field);
        self::assertSame($code, $refusal->code);
    }

    public function testValidate_AKeyIsNeverQuotedInTheMessage(): void
    {
        $refusal = (new MollieConfigChangeValidator())->validate(new MollieConfigChange('v', testKey: 'test_SECRETSECRETSEC RET'));

        self::assertStringNotContainsString('SECRETSECRET', (string) $refusal?->message);
    }
}
