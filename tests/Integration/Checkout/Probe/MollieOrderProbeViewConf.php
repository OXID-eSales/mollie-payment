<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Integration\Checkout\Probe;

/**
 * Stands in for the shop's oViewConf global: a Twig context variable of the
 * same name shadows the global, so the inline-card decision and the method
 * list are under the test's control.
 */
class MollieOrderProbeViewConf
{
    /** @param list<array{id: string, name: string, image: string|null}> $methods */
    public function __construct(
        private readonly array $methods,
        private readonly bool $inlineCard = true,
    ) {
    }

    public function getMollieModuleId(): string
    {
        return 'oe_payments_mollie';
    }

    public function isMollieInlineCardEnabled(): bool
    {
        return $this->inlineCard;
    }

    /** @return list<array{id: string, name: string, image: string|null}> */
    public function getMollieMethods(): array
    {
        return $this->methods;
    }

    public function getMollieProfileId(): string
    {
        return 'pfl_probe';
    }

    public function isMollieTestMode(): bool
    {
        return true;
    }

    public function getMollieComponentsLocale(): string
    {
        return 'en_US';
    }

    public function isMollieDebugLoggingEnabled(): bool
    {
        return false;
    }

    public function getMollieJsPath(): string
    {
        return 'js/mollie-frontend.min.js';
    }

    public function getMollieModuleVersion(): string
    {
        return '0.0.0-test';
    }

    /** @param array<int, mixed> $args */
    public function getModuleUrl(string $moduleId, string $path = '', array ...$args): string
    {
        return 'https://shop.test/out/modules/' . $moduleId . '/' . $path;
    }

    public function getSslSelfLink(): string
    {
        return 'https://shop.test/index.php?';
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}
