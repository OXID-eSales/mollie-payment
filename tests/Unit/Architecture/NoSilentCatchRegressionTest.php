<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Sprint 11 Story 8 — no `catch` block in `src/` may be empty or comment-only.
 *
 * The fallback audit's single strongest finding was not any individual bug but the pattern behind
 * them: almost every dangerous fallback lived in a class with no logger, so when it fired nobody
 * learned anything. A swallowed exception with a reassuring comment is the most common shape of
 * that, and a comment is not a signal — it is documentation of a decision whose consequences are
 * invisible at runtime.
 *
 * The allowlist below is the point of this test. Adding to it is a deliberate act that has to be
 * justified in writing, rather than a silent `catch (Throwable) {}` slipping through review.
 */
#[Group('architecture')]
final class NoSilentCatchRegressionTest extends TestCase
{
    /**
     * Catches that are allowed to swallow without logging, and why.
     *
     * Keyed `RelativePath::approximateContext`. Keep this list short; every entry is a place where
     * a runtime failure is genuinely invisible.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // Best-effort audit trail: the file logger is itself the logging mechanism, so a failure to
        // build it cannot be logged through it, and it must never block webhook processing.
        'src/Mollie/Controller/Webhook/WebhookController.php' => 'file-logger construction is best-effort',
    ];

    public function testNoCatchBlockIsEmptyOrCommentOnly(): void
    {
        $offenders = [];

        foreach ($this->phpFilesInSrc() as $file) {
            $relative = $this->relativePath($file);
            $source = (string) file_get_contents($file->getPathname());

            foreach ($this->silentCatchLines($source) as $line) {
                if (array_key_exists($relative, self::ALLOWED)) {
                    continue;
                }
                $offenders[] = $relative . ':' . $line;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Silent catch block(s) found. Log the swallowed throwable, or add the file to "
            . "NoSilentCatchRegressionTest::ALLOWED with a written reason:\n  "
            . implode("\n  ", $offenders),
        );
    }

    public function testAllowlistEntriesStillExist(): void
    {
        foreach (array_keys(self::ALLOWED) as $relative) {
            self::assertFileExists(
                dirname(__DIR__, 3) . '/' . $relative,
                sprintf('Allowlisted file %s is gone — drop the entry.', $relative),
            );
        }
    }

    /**
     * Line numbers of `catch (...) { }` blocks whose body contains no statement.
     *
     * Deliberately a dumb scan rather than an AST parse: the shape being looked for is textual and
     * unambiguous, and a parser dependency would be a heavier answer than the question deserves.
     *
     * @return list<int>
     */
    private function silentCatchLines(string $source): array
    {
        $lines = [];
        $offset = 0;

        while (($catchPos = strpos($source, 'catch (', $offset)) !== false) {
            $offset = $catchPos + 7;

            $bracePos = strpos($source, '{', $catchPos);
            if ($bracePos === false) {
                continue;
            }

            $closePos = $this->matchingBrace($source, $bracePos);
            if ($closePos === null) {
                continue;
            }

            $body = substr($source, $bracePos + 1, $closePos - $bracePos - 1);
            if ($this->stripComments($body) === '') {
                $lines[] = substr_count(substr($source, 0, $catchPos), "\n") + 1;
            }
        }

        return $lines;
    }

    private function matchingBrace(string $source, int $openPos): ?int
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $openPos; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
                continue;
            }
            if ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function stripComments(string $body): string
    {
        $body = (string) preg_replace('#/\*.*?\*/#s', '', $body);
        $body = (string) preg_replace('#//[^\n]*#', '', $body);

        return trim($body);
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFilesInSrc(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function relativePath(SplFileInfo $file): string
    {
        $root = dirname(__DIR__, 3) . '/';

        return str_replace($root, '', $file->getPathname());
    }
}
