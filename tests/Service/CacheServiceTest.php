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

use DateTimeImmutable;
use KonradMichalik\ComposerDependencyAge\Exception\CacheException;
use KonradMichalik\ComposerDependencyAge\Service\CacheService;
use PHPUnit\Framework\TestCase;

/**
 * CacheServiceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class CacheServiceTest extends TestCase
{
    private CacheService $cacheService;
    private string $tempCacheFile;

    protected function setUp(): void
    {
        $this->tempCacheFile = sys_get_temp_dir().'/composer-dependency-age-test-'.uniqid();
        $this->cacheService = new CacheService($this->tempCacheFile, 3600); // 1 hour TTL
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempCacheFile)) {
            unlink($this->tempCacheFile);
        }
    }

    public function testStoreAndRetrievePackageInfo(): void
    {
        $packageData = [
            'release_date' => '2023-01-01T12:00:00+00:00',
            'latest_version' => '1.0.1',
            'latest_release_date' => '2023-02-01T12:00:00+00:00',
        ];

        // Store package info
        $this->cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Retrieve package info
        $retrieved = $this->cacheService->getPackageInfo('vendor/package', '1.0.0');

        $this->assertIsArray($retrieved);
        $this->assertEquals('2023-01-01T12:00:00+00:00', $retrieved['release_date']);
        $this->assertEquals('1.0.1', $retrieved['latest_version']);
        $this->assertEquals('2023-02-01T12:00:00+00:00', $retrieved['latest_release_date']);
        $this->assertArrayHasKey('cached_at', $retrieved);
    }

    public function testGetNonExistentPackageInfo(): void
    {
        $result = $this->cacheService->getPackageInfo('non-existent/package', '1.0.0');
        $this->assertNull($result);
    }

    public function testCacheValidityWhenFileDoesNotExist(): void
    {
        $this->assertFalse($this->cacheService->isCacheValid());
    }

    public function testCacheValidityWithValidCache(): void
    {
        $packageData = [
            'release_date' => '2023-01-01T12:00:00+00:00',
        ];

        $this->cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);
        $this->assertTrue($this->cacheService->isCacheValid());
    }

    public function testCacheValidityWithExpiredCache(): void
    {
        // Create a cache service with very short TTL
        $shortTtlCache = new CacheService($this->tempCacheFile, 1); // 1 second TTL

        $packageData = [
            'release_date' => '2023-01-01T12:00:00+00:00',
        ];

        $shortTtlCache->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Wait for cache to expire
        sleep(2);

        $this->assertFalse($shortTtlCache->isCacheValid());
    }

    public function testClearCache(): void
    {
        $packageData = [
            'release_date' => '2023-01-01T12:00:00+00:00',
        ];

        // Store some data
        $this->cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);
        $this->assertFileExists($this->tempCacheFile);

        // Clear cache
        $this->cacheService->clearCache();
        $this->assertFileDoesNotExist($this->tempCacheFile);
    }

    public function testGetCacheStatsEmpty(): void
    {
        $stats = $this->cacheService->getCacheStats();

        $this->assertFalse($stats['exists']);
        $this->assertEquals(0, $stats['size']);
        $this->assertEquals(0, $stats['packages']);
        $this->assertEquals(0, $stats['entries']);
    }

    public function testGetCacheStatsWithData(): void
    {
        $packageData = [
            'release_date' => '2023-01-01T12:00:00+00:00',
        ];

        // Store multiple packages
        $this->cacheService->storePackageInfo('vendor/package1', '1.0.0', $packageData);
        $this->cacheService->storePackageInfo('vendor/package1', '2.0.0', $packageData);
        $this->cacheService->storePackageInfo('vendor/package2', '1.0.0', $packageData);

        $stats = $this->cacheService->getCacheStats();

        $this->assertTrue($stats['exists']);
        $this->assertGreaterThan(0, $stats['size']);
        $this->assertEquals(2, $stats['packages']); // 2 different packages
        $this->assertEquals(3, $stats['entries']); // 3 total entries
        $this->assertTrue($stats['valid']);
    }

    public function testExpiredPackageInfoReturnsNull(): void
    {
        // Create cache with short TTL
        $shortTtlCache = new CacheService($this->tempCacheFile, 1); // 1 second TTL

        $packageData = [
            'release_date' => '2023-01-01T12:00:00+00:00',
        ];

        // Store package info
        $shortTtlCache->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Wait for expiration
        sleep(2);

        // Should return null for expired data
        $result = $shortTtlCache->getPackageInfo('vendor/package', '1.0.0');
        $this->assertNull($result);
    }

    public function testCacheFileFormatValidation(): void
    {
        // Write invalid JSON to cache file
        file_put_contents($this->tempCacheFile, '{"invalid": json');

        // Should handle gracefully and create new cache
        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];

        // This should not throw an exception but handle gracefully
        $this->cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);

        $retrieved = $this->cacheService->getPackageInfo('vendor/package', '1.0.0');
        $this->assertIsArray($retrieved);
    }

    public function testCacheVersionCompatibility(): void
    {
        // Write cache with different version
        $oldCache = [
            'version' => '0.9',
            'created' => (new DateTimeImmutable())->format('c'),
            'ttl' => 3600,
            'packages' => [
                'vendor/package' => [
                    '1.0.0' => [
                        'release_date' => '2023-01-01T12:00:00+00:00',
                        'cached_at' => (new DateTimeImmutable())->format('c'),
                    ],
                ],
            ],
        ];

        file_put_contents($this->tempCacheFile, json_encode($oldCache));

        // Should not find the package due to version incompatibility
        $result = $this->cacheService->getPackageInfo('vendor/package', '1.0.0');
        $this->assertNull($result);
    }

    public function testMultiplePackageVersions(): void
    {
        $packageData1 = ['release_date' => '2023-01-01T12:00:00+00:00'];
        $packageData2 = ['release_date' => '2023-02-01T12:00:00+00:00'];

        // Store multiple versions of same package
        $this->cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData1);
        $this->cacheService->storePackageInfo('vendor/package', '2.0.0', $packageData2);

        // Retrieve both versions
        $retrieved1 = $this->cacheService->getPackageInfo('vendor/package', '1.0.0');
        $retrieved2 = $this->cacheService->getPackageInfo('vendor/package', '2.0.0');

        $this->assertIsArray($retrieved1);
        $this->assertIsArray($retrieved2);
        $this->assertEquals('2023-01-01T12:00:00+00:00', $retrieved1['release_date']);
        $this->assertEquals('2023-02-01T12:00:00+00:00', $retrieved2['release_date']);
    }

    public function testCacheWriteFailure(): void
    {
        // Create service with invalid cache file path
        $invalidCacheService = new CacheService('/invalid/path/cache.json');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Failed to write cache file');

        $invalidCacheService->storePackageInfo('vendor/package', '1.0.0', [
            'release_date' => '2023-01-01T12:00:00+00:00',
        ]);
    }

    public function testCacheReadFailure(): void
    {
        // Create cache service with existing but unreadable file
        $cacheService = new CacheService('/dev/null');

        // This should handle gracefully by creating empty cache
        $result = $cacheService->getPackageInfo('vendor/package', '1.0.0');
        $this->assertNull($result);
    }
}
