<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\GraphQL\Admin;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * oxid-admin-graphql is optional for the Mollie module (payment-base's approach): a hard reference to one of its
 * services breaks the container compile of a shop without it, and a class that loads a missing package breaks the
 * module activation (the container reflects every service class).
 */
final class AdminApiIsOptionalTest extends TestCase
{
    private const ADMIN_API = 'OxidEsales\\GraphQL\\Admin\\';

    public function testEveryReferenceToAServiceOfTheAdminApiIsOptional(): void
    {
        $services = $this->services();

        $hard = [];
        array_walk_recursive($services, static function (mixed $value) use (&$hard): void {
            if (is_string($value) && str_starts_with($value, '@' . self::ADMIN_API)) {
                $hard[] = $value;
            }
        });

        self::assertSame([], $hard, 'a hard reference to an admin API service breaks the compile without it: use @?');
        self::assertContains(
            '@?OxidEsales\\GraphQL\\Admin\\Audit\\Service\\AuditRecorder',
            $this->flattenedReferences($services),
            'the audit recorder is wired optionally',
        );
    }

    public function testNoServiceIsDefinedForAClassOfTheAdminApiItself(): void
    {
        foreach (array_keys($this->services()) as $id) {
            self::assertStringStartsNotWith(self::ADMIN_API, (string) $id, 'the Mollie module must not redefine a service of the admin API');
        }
    }

    public function testTheAdminServicesAreRegisteredAndNoneIsAutowired(): void
    {
        $services = $this->services();
        $admin = array_filter($services, static fn (string $id): bool => str_contains($id, 'Mollie\\GraphQL\\Admin\\'), ARRAY_FILTER_USE_KEY);

        self::assertGreaterThanOrEqual(5, count($admin));
        foreach ($admin as $id => $definition) {
            self::assertIsArray($definition, $id);
            if (isset($definition['alias'])) {
                continue; // a public alias of a private definition (RoutePass): nothing to wire
            }
            self::assertFalse($definition['autowire'] ?? true, $id . ' must wire its arguments explicitly (optional packages)');
        }
    }

    public function testEveryAdminServiceClassLoadsWhereNoOptionalPackageExists(): void
    {
        $script = __DIR__ . '/Support/reflect-services-without-admin.php';
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertMatchesRegularExpression('/^OK (\d+)$/m', $output, $output);
        preg_match('/^OK (\d+)$/m', $output, $matches);
        self::assertGreaterThanOrEqual(5, (int) $matches[1], 'the admin services were actually reflected');
    }

    public function testEveryServiceOfTheModuleLoadsWhereNoGraphQlPackageCanBeLoaded(): void
    {
        $script = __DIR__ . '/Support/reflect-all-services-without-graphql.php';
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertMatchesRegularExpression('/^OK (\d+)$/m', $output, $output);
        preg_match('/^OK (\d+)$/m', $output, $matches);
        self::assertGreaterThanOrEqual(60, (int) $matches[1], 'the services of the whole module were reflected');
    }

    /**
     * @return array<string, mixed>
     */
    private function services(): array
    {
        return Yaml::parseFile(__DIR__ . '/../../../../services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];
    }

    /**
     * @param array<string, mixed> $services
     * @return list<string>
     */
    private function flattenedReferences(array $services): array
    {
        $references = [];
        array_walk_recursive($services, static function (mixed $value) use (&$references): void {
            if (is_string($value) && str_starts_with($value, '@')) {
                $references[] = $value;
            }
        });

        return $references;
    }
}
