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
use KonradMichalik\ComposerDependencyAge\Service\CacheService;
use PHPUnit\Framework\TestCase;

/**
 * CacheCleanupTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class CacheCleanupTest extends TestCase
{
    private string $tempCacheFile;

    protected function setUp(): void
    {
        $this->tempCacheFile = sys_get_temp_dir().'/composer-dependency-age-cleanup-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempCacheFile)) {
            unlink($this->tempCacheFile);
        }
    }

    public function testCacheSizeTracking(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 3600);

        // Initially no cache file
        $stats = $cacheService->getCacheStats();
        $this->assertFalse($stats['exists']);
        $this->assertEquals(0, $stats['size']);

        // Store some data
        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];
        $cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Should have size now
        $stats = $cacheService->getCacheStats();
        $this->assertTrue($stats['exists']);
        $this->assertGreaterThan(0, $stats['size']);
    }

    public function testCacheStatsAccuracy(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 3600);

        // Store multiple packages with different versions
        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];

        $cacheService->storePackageInfo('vendor/package1', '1.0.0', $packageData);
        $cacheService->storePackageInfo('vendor/package1', '2.0.0', $packageData);
        $cacheService->storePackageInfo('vendor/package2', '1.0.0', $packageData);

        $stats = $cacheService->getCacheStats();

        $this->assertEquals(2, $stats['packages']); // 2 different packages
        $this->assertEquals(3, $stats['entries']); // 3 total entries
        $this->assertTrue($stats['exists']);
        $this->assertGreaterThan(0, $stats['size']);
    }

    public function testAutomaticCleanupOfExpiredEntries(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 1); // 1 second TTL

        // Store some data
        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];
        $cacheService->storePackageInfo('vendor/package1', '1.0.0', $packageData);
        $cacheService->storePackageInfo('vendor/package2', '1.0.0', $packageData);

        // Initial stats
        $stats = $cacheService->getCacheStats();
        $this->assertEquals(2, $stats['packages']);
        $this->assertEquals(2, $stats['entries']);

        // Wait for expiration
        sleep(2);

        // Store new package (this should trigger cleanup during save)
        $cacheService->storePackageInfo('vendor/package3', '1.0.0', $packageData);

        // The cache should have been cleaned up, but we can't easily test the internal cleanup
        // without exposing internals. We can test that expired entries return null.
        $result1 = $cacheService->getPackageInfo('vendor/package1', '1.0.0');
        $result2 = $cacheService->getPackageInfo('vendor/package2', '1.0.0');
        $result3 = $cacheService->getPackageInfo('vendor/package3', '1.0.0');

        // Old entries should be expired (null), new one should be available
        $this->assertNull($result1);
        $this->assertNull($result2);
        $this->assertIsArray($result3);
    }

    public function testClearCacheRemovesFile(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 3600);

        // Store some data to create the cache file
        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];
        $cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Verify file exists
        $this->assertFileExists($this->tempCacheFile);

        // Clear cache
        $cacheService->clearCache();

        // File should be gone
        $this->assertFileDoesNotExist($this->tempCacheFile);

        // Stats should reflect empty state
        $stats = $cacheService->getCacheStats();
        $this->assertFalse($stats['exists']);
        $this->assertEquals(0, $stats['size']);
        $this->assertEquals(0, $stats['packages']);
        $this->assertEquals(0, $stats['entries']);
    }

    public function testCacheWithLargeData(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 3600);

        // Create larger package data
        $largePackageData = [
            'release_date' => '2023-01-01T12:00:00+00:00',
            'latest_version' => '2.0.0',
            'latest_release_date' => '2023-06-01T12:00:00+00:00',
            'description' => str_repeat('This is a long description. ', 100),
            'keywords' => array_fill(0, 50, 'keyword'),
            'authors' => array_fill(0, 10, ['name' => 'Author Name', 'email' => 'author@example.com']),
        ];

        // Store multiple large entries
        for ($i = 0; $i < 10; ++$i) {
            $cacheService->storePackageInfo("vendor/package{$i}", '1.0.0', $largePackageData);
        }

        $stats = $cacheService->getCacheStats();

        // Should have stored all packages
        $this->assertEquals(10, $stats['packages']);
        $this->assertEquals(10, $stats['entries']);
        $this->assertGreaterThan(1000, $stats['size']); // Should be reasonably large

        // Should be able to retrieve all data
        for ($i = 0; $i < 10; ++$i) {
            $result = $cacheService->getPackageInfo("vendor/package{$i}", '1.0.0');
            $this->assertIsArray($result);
            $this->assertEquals('2023-01-01T12:00:00+00:00', $result['release_date']);
        }
    }

    public function testCacheHandlesCorruptedFile(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 3600);

        // Write corrupted JSON to cache file
        file_put_contents($this->tempCacheFile, '{"corrupted": json}');

        // File exists but should be treated as invalid/empty
        $this->assertFileExists($this->tempCacheFile);

        // Should handle gracefully and create new cache structure
        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];
        $cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Should be able to retrieve the data (proving cache was recreated)
        $result = $cacheService->getPackageInfo('vendor/package', '1.0.0');
        $this->assertIsArray($result);

        // Cache stats should now show valid cache
        $stats = $cacheService->getCacheStats();
        $this->assertTrue($stats['exists']);
        $this->assertEquals(1, $stats['packages']);
    }

    public function testCacheValidityWithMixedExpiryStates(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 3600); // 1 hour TTL

        // Create cache with mixed expiry states by manually crafting content
        $now = new DateTimeImmutable();
        $oldTime = $now->modify('-2 hours'); // Expired
        $recentTime = $now->modify('-10 minutes'); // Still valid

        $cacheContent = [
            'version' => '1.0',
            'created' => $now->format('c'),
            'ttl' => 3600,
            'packages' => [
                'vendor/expired' => [
                    '1.0.0' => [
                        'release_date' => '2023-01-01T12:00:00+00:00',
                        'cached_at' => $oldTime->format('c'),
                    ],
                ],
                'vendor/valid' => [
                    '1.0.0' => [
                        'release_date' => '2023-02-01T12:00:00+00:00',
                        'cached_at' => $recentTime->format('c'),
                    ],
                ],
            ],
        ];

        file_put_contents($this->tempCacheFile, json_encode($cacheContent));

        // Cache should be considered valid overall (created recently)
        $this->assertTrue($cacheService->isCacheValid());

        // But expired individual entries should return null
        $expiredResult = $cacheService->getPackageInfo('vendor/expired', '1.0.0');
        $this->assertNull($expiredResult);

        // Valid entries should return data
        $validResult = $cacheService->getPackageInfo('vendor/valid', '1.0.0');
        $this->assertIsArray($validResult);
        $this->assertEquals('2023-02-01T12:00:00+00:00', $validResult['release_date']);
    }

    public function testEmptyPackageCleanup(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 1); // 1 second TTL

        // Store data in multiple versions of same package
        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];
        $cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);
        $cacheService->storePackageInfo('vendor/package', '2.0.0', $packageData);

        // Initial stats
        $stats = $cacheService->getCacheStats();
        $this->assertEquals(1, $stats['packages']); // 1 package
        $this->assertEquals(2, $stats['entries']); // 2 versions

        // Wait for expiration
        sleep(2);

        // Try to access expired data (this should trigger internal cleanup)
        $result1 = $cacheService->getPackageInfo('vendor/package', '1.0.0');
        $result2 = $cacheService->getPackageInfo('vendor/package', '2.0.0');

        $this->assertNull($result1);
        $this->assertNull($result2);

        // Store new data to trigger save (and potential cleanup)
        $cacheService->storePackageInfo('vendor/newpackage', '1.0.0', $packageData);

        // Should have the new package
        $result = $cacheService->getPackageInfo('vendor/newpackage', '1.0.0');
        $this->assertIsArray($result);
    }
}
