<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieConfigurationException;
use OxidEsales\Payments\Mollie\Adapter\MollieConnectionAdapterInterface;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConnectionChecker;
use OxidEsales\Payments\Mollie\GraphQL\Admin\Service\MollieConnectionStatus;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class MollieConnectionCheckerTest extends TestCase
{
    private const KEY = 'test_AbCdEfGhIjKlMnOpQrStUvWxYz0123';

    public function testCheck_MollieAcceptsTheKey_IsOkForTheActiveMode(): void
    {
        $status = $this->checker(null)->check();

        self::assertSame(MollieConnectionStatus::OK, $status->status);
        self::assertTrue($status->isOk());
        self::assertSame('test', $status->mode);
    }

    public function testCheck_NoKeyForTheActiveMode_IsNotConfiguredAndMollieIsNotAsked(): void
    {
        $adapter = $this->adapterThrowing(new RuntimeException('must not be called'));

        $status = $this->checker($adapter, apiKey: '', mode: 'live')->check();

        self::assertSame(MollieConnectionStatus::NOT_CONFIGURED, $status->status);
        self::assertSame('live', $status->mode);
    }

    public function testCheck_UnreadableSettings_AreNotConfigured(): void
    {
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);
        $config->method('getMode')->willThrowException(new MollieConfigurationException('unreadable'));

        $status = (new MollieConnectionChecker($config, $this->adapterThrowing(null), $this->createStub(LoggerInterface::class)))->check();

        self::assertSame(MollieConnectionStatus::NOT_CONFIGURED, $status->status);
    }

    public function testCheck_AMalformedKey_IsInvalid(): void
    {
        $status = $this->checker($this->adapterThrowing(new MollieConfigurationException('Mollie API key is malformed')))->check();

        self::assertSame(MollieConnectionStatus::INVALID_KEY, $status->status);
    }

    public function testCheck_MollieRefusesTheKey_WithUnauthorizedOrForbidden_IsInvalid(): void
    {
        foreach ([401, 403] as $http) {
            $status = $this->checker($this->adapterThrowing(new MollieAdapterException('refused', $http)))->check();

            self::assertSame(MollieConnectionStatus::INVALID_KEY, $status->status, (string) $http);
        }
    }

    public function testCheck_AnyOtherFailure_IsUnreachableNotAVerdictOnTheKey(): void
    {
        foreach ([new MollieAdapterException('down', 503), new MollieAdapterException('no answer', 0), new MollieAdapterException('slow', 429), new RuntimeException('curl')] as $failure) {
            $status = $this->checker($this->adapterThrowing($failure))->check();

            self::assertSame(MollieConnectionStatus::UNREACHABLE, $status->status, $failure->getMessage());
        }
    }

    public function testCheck_NeitherTheKeyNorMollieSMessageReachesTheLog(): void
    {
        $records = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->willReturnCallback(
            static function (string|\Stringable $message, array $context) use (&$records): void {
                $records[] = [(string) $message, $context];
            }
        );

        $this->checker($this->adapterThrowing(new MollieAdapterException('Invalid ' . self::KEY, 401)), logger: $logger)->check();

        self::assertStringNotContainsString(self::KEY, (string) json_encode($records));
        self::assertStringNotContainsString('Invalid', (string) json_encode($records));
    }

    private function checker(?MollieConnectionAdapterInterface $adapter, string $apiKey = self::KEY, string $mode = 'test', ?LoggerInterface $logger = null): MollieConnectionChecker
    {
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);
        $config->method('getMode')->willReturn($mode);
        $config->method('getApiKey')->willReturn($apiKey);

        return new MollieConnectionChecker($config, $adapter ?? $this->adapterThrowing(null), $logger ?? $this->createStub(LoggerInterface::class));
    }

    private function adapterThrowing(?Throwable $failure): MollieConnectionAdapterInterface
    {
        return new class ($failure) implements MollieConnectionAdapterInterface {
            public function __construct(private readonly ?Throwable $failure)
            {
            }

            public function verifyConnection(): void
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }
            }
        };
    }
}
