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
 * Locks the SDK boundary: only classes under src/Mollie/Adapter/ may import the Mollie SDK
 * (`use Mollie\Api\…`). Any leak into services/handlers/controllers fails this test.
 */
final class NoDirectSdkImportsRegressionTest extends TestCase
{
    public function testNoMollieApiImportOutsideAdapterDirectory(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src/Mollie';
        $violations = [];

        foreach ($this->phpFiles($srcDir) as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/Adapter/')) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (preg_match('/use\s+Mollie\\\\Api\\\\/', $contents) === 1) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations, "Mollie SDK imported outside Adapter/:\n" . implode("\n", $violations));
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
