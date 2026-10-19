<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigProviderInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\MollieClientConfigProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieClientConfigProvider::class)]
final class MollieClientConfigProviderTest extends TestCase
{
    private const TEST_KEY = 'test_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const LIVE_KEY = 'live_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testIsAClientConfigProviderForMollie(): void
    {
        $provider = $this->providerFor([]);

        self::assertInstanceOf(ClientConfigProviderInterface::class, $provider);
        self::assertSame('mollie', $provider->providerId());
    }

    public function testPublishesProfileIdAndTestModeTrue(): void
    {
        $provider = $this->providerFor([
            'sMollieProfileId' => 'pfl_cVf8bSWdQV',
            'sMollieMode' => MollieDefinitions::MODE_TEST,
        ]);

        self::assertSame(['profileId' => 'pfl_cVf8bSWdQV', 'testMode' => true], $provider->publicValues());
    }

    public function testPublishesTestModeFalseInLiveMode(): void
    {
        $provider = $this->providerFor([
            'sMollieProfileId' => 'pfl_cVf8bSWdQV',
            'sMollieMode' => MollieDefinitions::MODE_LIVE,
        ]);

        self::assertSame(['profileId' => 'pfl_cVf8bSWdQV', 'testMode' => false], $provider->publicValues());
    }

    public function testPublishesNothingWhenProfileIdIsUnset(): void
    {
        $provider = $this->providerFor(['sMollieMode' => MollieDefinitions::MODE_LIVE]);

        self::assertSame([], $provider->publicValues());
    }

    public function testNeverPublishesApiKeysEvenWhenConfigured(): void
    {
        $provider = $this->providerFor([
            'sMollieProfileId' => 'pfl_cVf8bSWdQV',
            'sMollieMode' => MollieDefinitions::MODE_LIVE,
            'sMollieTestKey' => self::TEST_KEY,
            'sMollieLiveKey' => self::LIVE_KEY,
        ]);

        $values = $provider->publicValues();

        self::assertSame(['profileId', 'testMode'], array_keys($values));
        self::assertStringNotContainsString(self::TEST_KEY, (string) json_encode($values));
        self::assertStringNotContainsString(self::LIVE_KEY, (string) json_encode($values));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function providerFor(array $settings): MollieClientConfigProvider
    {
        return new MollieClientConfigProvider(new TestableModuleConfigurationService($settings));
    }
}
