<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Module;

use OxidEsales\Payments\Mollie\Gateway\MollieCancelGateway;
use OxidEsales\Payments\Mollie\Gateway\MollieCaptureGateway;
use OxidEsales\Payments\Mollie\Gateway\MollieRefundGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * payment-base finds a provider's ports by tag (`ProviderGatewayRegistry` is built from `!tagged_iterator`): a gateway
 * that is not tagged is invisible and the admin's capture, refund or void is refused `provider_unavailable`. The
 * registry is not a public service, so the wiring is pinned where it is written.
 */
final class PaymentBaseGatewayTagsTest extends TestCase
{
    #[DataProvider('taggedServices')]
    public function testTheServiceIsTaggedForPaymentBase(string $serviceId, string $tag): void
    {
        $services = Yaml::parseFile(dirname(__DIR__, 3) . '/services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];

        self::assertArrayHasKey($serviceId, $services);
        self::assertContains($tag, array_column($services[$serviceId]['tags'] ?? [], 'name'), $serviceId . ' must carry ' . $tag);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function taggedServices(): iterable
    {
        yield 'capture' => [MollieCaptureGateway::class, 'oe.payment.gateway'];
        yield 'refund' => [MollieRefundGateway::class, 'oe.payment.gateway'];
        yield 'void' => [MollieCancelGateway::class, 'oe.payment.gateway'];
    }
}
