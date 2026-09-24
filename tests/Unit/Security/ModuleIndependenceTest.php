<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * MOL-15: Mollie adopts payment-base's validation system exactly the way the Stripe module does,
 * and stays independent of it while doing so. Both modules depend on payment-base only.
 */
final class ModuleIndependenceTest extends TestCase
{
    private const FORBIDDEN = [
        'OxidEsales\\Payments\\Stripe',
        'oe_payments_stripe',
    ];

    public function testNoSourceFileReferencesTheStripeModule(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $file) {
            $content = (string) file_get_contents($file->getPathname());
            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($content, $needle)) {
                    $offenders[] = $file->getPathname() . ' contains ' . $needle;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function sourceFiles(): iterable
    {
        $root = dirname(__DIR__, 3);
        foreach (['src', 'services.yaml', 'metadata.php'] as $path) {
            $full = $root . '/' . $path;
            if (is_file($full)) {
                yield new SplFileInfo($full);
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'yaml', 'twig', 'js'], true)) {
                    yield $file;
                }
            }
        }
    }
}
