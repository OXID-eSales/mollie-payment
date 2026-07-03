<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The payment-base contract advances only through named transitions (transitionToPending,
 * authorize, captureAuthorization, commitToOrder, fulfill, cancel, fail, expire). There is no
 * generic setState(); calling one would be a design regression (Stripe sprint 44 lesson).
 */
final class NoSetStateOnContractRegressionTest extends TestCase
{
    public function testNoContractSetStateCalls(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src/Mollie';
        $violations = [];

        foreach ($this->phpFiles($srcDir) as $file) {
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/->\s*setState\s*\(/', $contents) === 1) {
                $violations[] = $file->getPathname();
            }
        }

        self::assertSame([], $violations, "Contract setState() call found:\n" . implode("\n", $violations));
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function phpFiles(string $dir): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}
