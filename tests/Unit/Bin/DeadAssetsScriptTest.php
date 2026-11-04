<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Bin;

use PHPUnit\Framework\TestCase;

/**
 * Sprint 17 DC-5c: `bin/dead-assets` is the non-PHP half of the dead-code gate. It fails when a language key, a Twig
 * template or an asset file has no production reference. Run against a throw-away module root.
 */
final class DeadAssetsScriptTest extends TestCase
{
    private string $moduleRoot;

    protected function setUp(): void
    {
        $this->moduleRoot = sys_get_temp_dir() . '/mollie-dead-assets-' . bin2hex(random_bytes(6));
        $this->write('src/Panel.php', "<?php\n\$label = 'USED_KEY';\nconst LABEL_PREFIX = 'MOLLIE_LABEL_';\n");
        $this->write('metadata.php', "<?php\nreturn ['logo' => 'img/logo.png'];\n");
        $this->write('translations/en/mollie_lang.php', $this->langFile(['USED_KEY', 'MOLLIE_LABEL_CITY']));
        $this->write('views/twig/widget/footer.html.twig', "<p>{{ 'USED_KEY'|translate }}</p>\n");
        $this->write('src/Footer.php', "<?php\n\$template = 'widget/footer.html.twig';\n");
        $this->write('assets/img/logo.png', 'png');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->moduleRoot);
    }

    public function testACleanModulePasses(): void
    {
        [$exitCode] = $this->runScript();

        self::assertSame(0, $exitCode);
    }

    public function testAKeyOfTheTranslationsDirectoryWithoutAReferenceFails(): void
    {
        $this->write('translations/en/mollie_lang.php', $this->langFile(['USED_KEY', 'MOLLIE_LABEL_CITY', 'NEVER_USED_KEY']));

        [$exitCode, $output] = $this->runScript();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('NEVER_USED_KEY', $output);
    }

    public function testAKeyBuiltFromAPrefixHeldInAClassConstantCountsAsReferenced(): void
    {
        $this->write('src/Panel.php', "<?php\n\$label = 'USED_KEY';\nclass Labels { private const PREFIX = 'MOLLIE_LABEL_'; }\n");

        [$exitCode] = $this->runScript();

        self::assertSame(0, $exitCode);
    }

    public function testAnUnreferencedTemplateFails(): void
    {
        $this->write('views/twig/widget/orphan.html.twig', "<p>never included</p>\n");

        [$exitCode, $output] = $this->runScript();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('widget/orphan.html.twig', $output);
    }

    public function testAnUnreferencedAssetFailsUnlessItIsAllowListed(): void
    {
        $this->write('assets/js/bundle.js.map', '{}');
        [$exitCode] = $this->runScript();
        self::assertSame(1, $exitCode);

        $this->write('dead-assets.allow', "assets/js/bundle.js.map  # named by the bundle's sourceMappingURL\n");
        [$exitCode] = $this->runScript();
        self::assertSame(0, $exitCode);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runScript(): array
    {
        $script = dirname(__DIR__, 3) . '/bin/dead-assets';
        $process = proc_open([PHP_BINARY, $script, $this->moduleRoot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * @param list<string> $keys
     */
    private function langFile(array $keys): string
    {
        $lines = array_map(static fn (string $key): string => "    '" . $key . "' => 'text',", $keys);

        return "<?php\n\$aLang = [\n    'charset' => 'UTF-8',\n" . implode("\n", $lines) . "\n];\n";
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->moduleRoot . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
