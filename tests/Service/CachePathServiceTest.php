<?php

declare(strict_types=1);

/*
 * This file is part of the Composer plugin "composer-dependency-age".
 *
 * Copyright (C) 2025 Konrad Michalik <hej@konradmichalik.dev>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace KonradMichalik\ComposerDependencyAge\Tests\Service;

use KonradMichalik\ComposerDependencyAge\Service\CachePathService;
use PHPUnit\Framework\TestCase;

/**
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 *
 * @package ComposerDependencyAge
 */
final class CachePathServiceTest extends TestCase
{
    private CachePathService $service;

    protected function setUp(): void
    {
        $this->service = new CachePathService();
    }

    public function testGetCacheFilePathWithCustomPath(): void
    {
        $customPath = '/custom/cache/path.json';
        $result = $this->service->getCacheFilePath($customPath);

        $this->assertSame($customPath, $result);
    }

    public function testGetCacheFilePathWithoutCustomPath(): void
    {
        $result = $this->service->getCacheFilePath();

        // Should return some cache path
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('.dependency-age.cache', $result);
    }

    public function testGetSystemCachePath(): void
    {
        $result = $this->service->getSystemCachePath();

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('.dependency-age.cache', $result);
    }

    public function testGetSystemCachePathPlatformSpecific(): void
    {
        $result = $this->service->getSystemCachePath();

        // Should contain platform-specific directory structure
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->assertStringContainsString('Library/Caches', $result);
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $this->assertTrue(
                str_contains($result, '.cache') || str_contains($result, 'XDG_CACHE_HOME'),
            );
        } elseif (PHP_OS_FAMILY === 'Windows') {
            // Windows paths vary, but should be reasonable
            $this->assertNotEmpty($result);
        }
    }

    public function testIsCacheDirectoryWritableForExistingDirectory(): void
    {
        $tempDir = sys_get_temp_dir();
        $cachePath = $tempDir.'/.dependency-age.cache';

        $result = $this->service->isCacheDirectoryWritable($cachePath);

        // Temp directory should be writable
        $this->assertTrue($result);
    }

    public function testIsCacheDirectoryWritableForNonExistentDirectory(): void
    {
        $cachePath = '/non-existent-directory/.dependency-age.cache';

        $result = $this->service->isCacheDirectoryWritable($cachePath);

        // Should return false for non-existent, non-creatable directory
        $this->assertFalse($result);
    }

    public function testGetAllPotentialCachePaths(): void
    {
        $paths = $this->service->getAllPotentialCachePaths();

        $this->assertIsArray($paths);
        $this->assertNotEmpty($paths);

        // Should contain at least current directory and temp directory
        $currentDirPath = getcwd().'/.dependency-age.cache';
        $this->assertContains($currentDirPath, $paths);

        // All paths should end with cache filename
        foreach ($paths as $path) {
            $this->assertStringContainsString('.dependency-age.cache', $path);
        }

        // Should not contain duplicates
        $this->assertEquals($paths, array_unique($paths));
    }

    public function testCachePathsAreAbsolute(): void
    {
        $paths = $this->service->getAllPotentialCachePaths();

        foreach ($paths as $path) {
            $this->assertTrue(
                str_starts_with($path, '/') // Unix absolute path
                || (strlen($path) >= 3 && ':' === $path[1]), // Windows absolute path
                "Path should be absolute: {$path}",
            );
        }
    }

    /**
     * Test platform-specific behavior without mocking globals.
     */
    public function testPlatformSpecificPaths(): void
    {
        $systemPath = $this->service->getSystemCachePath();

        // Verify platform-appropriate path format
        match (PHP_OS_FAMILY) {
            // Windows paths might contain backslashes or use forward slashes
            'Windows' => $this->assertTrue(
                str_contains($systemPath, 'composer-dependency-age')
                && str_contains($systemPath, '.dependency-age.cache'),
            ),
            'Darwin' => $this->assertStringContainsString('Library/Caches/composer-dependency-age', $systemPath),
            // Linux and other Unix-like systems
            default => $this->assertTrue(
                str_contains($systemPath, '/.cache/composer-dependency-age')
                || str_contains($systemPath, '/composer-dependency-age'), // XDG case
            ),
        };
    }

    public function testCacheFilePathFallback(): void
    {
        // Test that we get a reasonable fallback even in edge cases
        $path = $this->service->getCacheFilePath();

        // Should be a non-empty string
        $this->assertNotEmpty($path);

        // Should be a valid path format
        $this->assertIsString($path);

        // Should contain the cache filename
        $this->assertStringContainsString('.dependency-age.cache', $path);
    }
}
