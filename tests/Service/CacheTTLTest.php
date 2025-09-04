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
 * CacheTTLTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class CacheTTLTest extends TestCase
{
    private string $tempCacheFile;

    protected function setUp(): void
    {
        $this->tempCacheFile = sys_get_temp_dir().'/composer-dependency-age-ttl-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempCacheFile)) {
            unlink($this->tempCacheFile);
        }
    }

    public function testCacheWithinTTLIsValid(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 3600); // 1 hour TTL

        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];
        $cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Should be valid immediately after storing
        $this->assertTrue($cacheService->isCacheValid());

        // Should be able to retrieve the data
        $retrieved = $cacheService->getPackageInfo('vendor/package', '1.0.0');
        $this->assertIsArray($retrieved);
        $this->assertEquals('2023-01-01T12:00:00+00:00', $retrieved['release_date']);
    }

    public function testCacheBeyondTTLIsInvalid(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 1); // 1 second TTL

        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];
        $cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Initially valid
        $this->assertTrue($cacheService->isCacheValid());

        // Wait for TTL to expire
        sleep(2);

        // Should be invalid after TTL expires
        $this->assertFalse($cacheService->isCacheValid());

        // Should return null for expired data
        $retrieved = $cacheService->getPackageInfo('vendor/package', '1.0.0');
        $this->assertNull($retrieved);
    }

    public function testDifferentTTLValues(): void
    {
        $shortTTL = new CacheService($this->tempCacheFile, 1); // 1 second
        $longTTL = new CacheService($this->tempCacheFile.'-long', 3600); // 1 hour

        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];

        // Store in both caches
        $shortTTL->storePackageInfo('vendor/package', '1.0.0', $packageData);
        $longTTL->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Both should be valid initially
        $this->assertTrue($shortTTL->isCacheValid());
        $this->assertTrue($longTTL->isCacheValid());

        // Wait for short TTL to expire
        sleep(2);

        // Short TTL should be invalid, long TTL should still be valid
        $this->assertFalse($shortTTL->isCacheValid());
        $this->assertTrue($longTTL->isCacheValid());

        // Clean up
        if (file_exists($this->tempCacheFile.'-long')) {
            unlink($this->tempCacheFile.'-long');
        }
    }

    public function testCacheStatsShowExpiration(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 1); // 1 second TTL

        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];
        $cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Initially should show as valid
        $stats = $cacheService->getCacheStats();
        $this->assertTrue($stats['valid']);

        // Wait for expiration
        sleep(2);

        // Stats should show as invalid
        $stats = $cacheService->getCacheStats();
        $this->assertFalse($stats['valid']);
    }

    public function testMixedTTLPackages(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 3600); // 1 hour default TTL

        // Store package with data that will expire soon (by manually setting cached_at)
        $oldTime = (new DateTimeImmutable())->modify('-2 hours');
        $expiredData = [
            'release_date' => '2023-01-01T12:00:00+00:00',
            'cached_at' => $oldTime->format('c'),
        ];

        // Store fresh data
        $freshData = ['release_date' => '2023-02-01T12:00:00+00:00'];

        // Manually create cache with mixed expiry times
        $cacheContent = [
            'version' => '1.0',
            'created' => (new DateTimeImmutable())->format('c'),
            'ttl' => 3600,
            'packages' => [
                'vendor/expired' => ['1.0.0' => $expiredData],
                'vendor/fresh' => ['1.0.0' => array_merge($freshData, [
                    'cached_at' => (new DateTimeImmutable())->format('c'),
                ])],
            ],
        ];

        file_put_contents($this->tempCacheFile, json_encode($cacheContent));

        // Expired package should return null
        $expiredResult = $cacheService->getPackageInfo('vendor/expired', '1.0.0');
        $this->assertNull($expiredResult);

        // Fresh package should return data
        $freshResult = $cacheService->getPackageInfo('vendor/fresh', '1.0.0');
        $this->assertIsArray($freshResult);
        $this->assertEquals('2023-02-01T12:00:00+00:00', $freshResult['release_date']);
    }

    public function testCacheInvalidationBehavior(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 2); // 2 seconds TTL

        // Store initial data
        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];
        $cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Verify it's stored
        $result1 = $cacheService->getPackageInfo('vendor/package', '1.0.0');
        $this->assertIsArray($result1);

        // Wait for expiration
        sleep(3);

        // Should return null after expiration
        $result2 = $cacheService->getPackageInfo('vendor/package', '1.0.0');
        $this->assertNull($result2);

        // Store new data (should work fine after expiration)
        $newPackageData = ['release_date' => '2023-03-01T12:00:00+00:00'];
        $cacheService->storePackageInfo('vendor/package', '1.0.0', $newPackageData);

        // Should retrieve new data
        $result3 = $cacheService->getPackageInfo('vendor/package', '1.0.0');
        $this->assertIsArray($result3);
        $this->assertEquals('2023-03-01T12:00:00+00:00', $result3['release_date']);
    }

    public function testZeroTTLDisablesCache(): void
    {
        $cacheService = new CacheService($this->tempCacheFile, 0); // No TTL

        $packageData = ['release_date' => '2023-01-01T12:00:00+00:00'];
        $cacheService->storePackageInfo('vendor/package', '1.0.0', $packageData);

        // Even immediately after storing, should return null with 0 TTL
        $result = $cacheService->getPackageInfo('vendor/package', '1.0.0');
        $this->assertNull($result);

        // Cache should be considered invalid
        $this->assertFalse($cacheService->isCacheValid());
    }
}
