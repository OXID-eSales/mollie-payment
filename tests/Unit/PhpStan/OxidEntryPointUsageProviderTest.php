<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\PhpStan;

use OxidEsales\Payments\Mollie\Core\Events;
use OxidEsales\Payments\Mollie\GraphQL\Controller\MollieCheckout;
use OxidEsales\Payments\Mollie\Service\CheckoutUserDataGate;
use OxidEsales\Payments\Mollie\Service\Factory\MollieAdapterFactory;
use OxidEsales\Payments\Mollie\Service\MolliePaidStatusProvider;
use OxidEsales\Payments\Mollie\Tests\PhpStan\DeadCode\OxidEntryPointUsageProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;

/**
 * The dead-code gate (sprint 17 DC-5) cannot see callers that live in a framework: the GraphQL schema builder, the
 * shop's `metadata.php` events, the template engine, the container and payment-base. This provider names them.
 */
final class OxidEntryPointUsageProviderTest extends TestCase
{
    private OxidEntryPointUsageProvider $provider;

    protected function setUp(): void
    {
        if (!class_exists(ReflectionBasedMemberUsageProvider::class)) {
            self::markTestSkipped('shipmonk/dead-code-detector is a dev dependency of the module checkout (composer dead-code).');
        }
        $this->provider = new OxidEntryPointUsageProvider(dirname(__DIR__, 3) . '/services.yaml');
    }

    public function testGraphQliteMutationIsAnEntryPoint(): void
    {
        self::assertTrue($this->provider->isEntryPoint(new ReflectionMethod(MollieCheckout::class, 'mollieCheckoutStart')));
    }

    public function testMetadataEventHandlerIsAnEntryPoint(): void
    {
        self::assertTrue($this->provider->isEntryPoint(new ReflectionMethod(Events::class, 'onActivate')));
    }

    public function testTheConstructorOfARegisteredServiceIsBuiltByTheContainer(): void
    {
        self::assertTrue($this->provider->isEntryPoint(new ReflectionMethod(CheckoutUserDataGate::class, '__construct')));
    }

    public function testAFactoryMethodOfServicesYamlIsCalledByTheContainer(): void
    {
        self::assertTrue($this->provider->isEntryPoint(new ReflectionMethod(MollieAdapterFactory::class, 'create')));
    }

    public function testAMethodThatImplementsAPaymentBaseContractIsCalledByPaymentBase(): void
    {
        self::assertTrue($this->provider->isEntryPoint(new ReflectionMethod(MolliePaidStatusProvider::class, 'statusOf')));
    }

    public function testPlainServiceMethodIsNotAnEntryPoint(): void
    {
        self::assertFalse($this->provider->isEntryPoint(new ReflectionMethod(self::class, 'testPlainServiceMethodIsNotAnEntryPoint')));
    }

    public function testPrivateMethodIsNotAnEntryPoint(): void
    {
        self::assertFalse($this->provider->isEntryPoint(new ReflectionMethod(OxidEntryPointUsageProvider::class, 'hasGraphQliteAttribute')));
    }

    /**
     * The members named as read by Twig must really occur in a template: a rename or a deleted template would
     * otherwise keep dead code alive for ever.
     */
    public function testEveryTwigReadMemberIsStillNamedInATemplate(): void
    {
        $templates = $this->templateCorpus();
        $stale = [];
        foreach (OxidEntryPointUsageProvider::TWIG_READ_MEMBERS as $class => $members) {
            foreach ($members as $member) {
                if (!str_contains($templates, $member)) {
                    $stale[] = $class . '::' . $member;
                }
            }
        }

        self::assertSame([], $stale, 'Not named in any template any more: remove them from TWIG_READ_MEMBERS.');
    }

    private function templateCorpus(): string
    {
        $corpus = '';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/views/twig', RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $corpus .= (string) file_get_contents($file->getPathname());
        }

        return $corpus;
    }
}
