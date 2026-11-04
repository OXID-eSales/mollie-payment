<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\PhpStan\DeadCode;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

/**
 * The classes the module's services.yaml registers: the container builds them, so their constructors have a caller
 * that no PHP file shows. Explicit definitions (id or `class:`) and `resource:` sweeps with their `exclude:` list.
 */
final class ServiceClassCollector
{
    public function __construct(private readonly string $servicesFile)
    {
    }

    /**
     * @return array<class-string, true>
     */
    public function collect(): array
    {
        $services = $this->definitions();
        $classes = [];
        foreach ($services as $id => $definition) {
            $classes = array_merge($classes, $this->classesOf((string) $id, $definition));
        }

        return $classes;
    }

    /**
     * Factory methods the container calls: class => method names (`factory: ['@Class', 'create']`).
     *
     * @return array<string, list<string>>
     */
    public function factoryMethods(): array
    {
        $factories = [];
        foreach ($this->definitions() as $definition) {
            $factory = is_array($definition) ? ($definition['factory'] ?? null) : null;
            if (!is_array($factory) || count($factory) !== 2) {
                continue;
            }
            [$service, $method] = array_values($factory);
            if (is_string($service) && is_string($method)) {
                $factories[ltrim($service, '@')][] = $method;
            }
        }

        return $factories;
    }

    /**
     * @return array<string, mixed>
     */
    private function definitions(): array
    {
        $yaml = Yaml::parseFile($this->servicesFile, Yaml::PARSE_CUSTOM_TAGS);

        return is_array($yaml['services'] ?? null) ? $yaml['services'] : [];
    }

    /**
     * @return array<class-string, true>
     */
    private function classesOf(string $id, mixed $definition): array
    {
        if (str_ends_with($id, '\\')) {
            return is_array($definition) ? $this->resourceClasses($id, $definition) : [];
        }
        $classes = [];
        if (class_exists($id)) {
            $classes[$id] = true;
        }
        $class = is_array($definition) ? ($definition['class'] ?? null) : null;
        if (is_string($class) && class_exists($class)) {
            $classes[$class] = true;
        }

        return $classes;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<class-string, true>
     */
    private function resourceClasses(string $namespace, array $definition): array
    {
        $resource = $definition['resource'] ?? null;
        if (!is_string($resource)) {
            return [];
        }
        $moduleRoot = dirname($this->servicesFile);
        $baseDir = rtrim(explode('*', $resource, 2)[0], '/');
        $excludes = array_map('strval', (array) ($definition['exclude'] ?? []));
        $classes = [];
        foreach ($this->phpFiles($moduleRoot . '/' . $baseDir) as $file) {
            $relativeToRoot = substr($file, strlen($moduleRoot) + 1);
            if ($this->isExcluded($relativeToRoot, $excludes)) {
                continue;
            }
            $class = $namespace . str_replace('/', '\\', substr($file, strlen($moduleRoot . '/' . $baseDir) + 1, -4));
            if (class_exists($class)) {
                $classes[$class] = true;
            }
        }

        return $classes;
    }

    /**
     * @param list<string> $excludes
     */
    private function isExcluded(string $path, array $excludes): bool
    {
        foreach ($excludes as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
