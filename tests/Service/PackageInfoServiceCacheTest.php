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
use KonradMichalik\ComposerDependencyAge\Api\PackagistClient;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Service\CacheService;
use KonradMichalik\ComposerDependencyAge\Service\PackageInfoService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test cache integration in PackageInfoService.
 */
final class PackageInfoServiceCacheTest extends TestCase
{
    private PackageInfoService $service;
    private PackagistClient&MockObject $mockClient;
    private CacheService&MockObject $mockCache;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(PackagistClient::class);
        $this->mockCache = $this->createMock(CacheService::class);
        $this->service = new PackageInfoService($this->mockClient, $this->mockCache);
    }

    public function testEnrichPackageWithCacheHit(): void
    {
        $package = new Package('vendor/package', '1.0.0');
        $cachedData = [
            'release_date' => '2023-01-01T12:00:00+00:00',
            'latest_version' => '1.0.1',
            'latest_release_date' => '2023-02-01T12:00:00+00:00',
            'cached_at' => '2023-07-01T12:00:00+00:00',
        ];

        // Mock cache hit
        $this->mockCache
            ->expects(self::once())
            ->method('getPackageInfo')
            ->with('vendor/package', '1.0.0')
            ->willReturn($cachedData);

        // API should not be called on cache hit
        $this->mockClient
            ->expects(self::never())
            ->method('getPackageInfo');

        $result = $this->service->enrichPackageWithReleaseInfo($package);

        $this->assertSame('vendor/package', $result->name);
        $this->assertSame('1.0.0', $result->version);
        $this->assertInstanceOf(DateTimeImmutable::class, $result->releaseDate);
        $this->assertSame('2023-01-01T12:00:00+00:00', $result->releaseDate->format('c'));
        $this->assertSame('1.0.1', $result->latestVersion);
        $this->assertInstanceOf(DateTimeImmutable::class, $result->latestReleaseDate);
        $this->assertSame('2023-02-01T12:00:00+00:00', $result->latestReleaseDate->format('c'));
    }

    public function testEnrichPackageWithCacheMiss(): void
    {
        $package = new Package('vendor/package', '1.0.0');

        // Mock cache miss
        $this->mockCache
            ->expects(self::once())
            ->method('getPackageInfo')
            ->with('vendor/package', '1.0.0')
            ->willReturn(null);

        // Mock API call
        $this->mockClient
            ->expects(self::once())
            ->method('getPackageInfo')
            ->with('vendor/package')
            ->willReturn([
                'packages' => [
                    'vendor/package' => [
                        [
                            'name' => 'vendor/package',
                            'version' => '1.0.0',
                            'time' => '2023-01-01T12:00:00+00:00',
                        ],
                        [
                            'name' => 'vendor/package',
                            'version' => '1.0.1',
                            'time' => '2023-02-01T12:00:00+00:00',
                        ],
                    ],
                ],
            ]);

        // Mock cache storage
        $this->mockCache
            ->expects(self::once())
            ->method('storePackageInfo')
            ->with(
                'vendor/package',
                '1.0.0',
                self::callback(fn (array $data): bool => isset($data['release_date'])
                    && '2023-01-01T12:00:00+00:00' === $data['release_date']
                    && isset($data['latest_version'])
                    && '1.0.1' === $data['latest_version']),
            );

        $result = $this->service->enrichPackageWithReleaseInfo($package);

        $this->assertSame('vendor/package', $result->name);
        $this->assertSame('1.0.0', $result->version);
        $this->assertInstanceOf(DateTimeImmutable::class, $result->releaseDate);
        $this->assertSame('2023-01-01T12:00:00+00:00', $result->releaseDate->format('c'));
    }

    public function testEnrichPackageWithoutCacheService(): void
    {
        $serviceWithoutCache = new PackageInfoService($this->mockClient, null);
        $package = new Package('vendor/package', '1.0.0');

        // Mock API call
        $this->mockClient
            ->expects(self::once())
            ->method('getPackageInfo')
            ->with('vendor/package')
            ->willReturn([
                'packages' => [
                    'vendor/package' => [
                        [
                            'name' => 'vendor/package',
                            'version' => '1.0.0',
                            'time' => '2023-01-01T12:00:00+00:00',
                        ],
                    ],
                ],
            ]);

        $result = $serviceWithoutCache->enrichPackageWithReleaseInfo($package);

        $this->assertSame('vendor/package', $result->name);
        $this->assertInstanceOf(DateTimeImmutable::class, $result->releaseDate);
    }

    public function testEnrichPackageWithPartialCachedData(): void
    {
        $package = new Package('vendor/package', '1.0.0');
        $cachedData = [
            'release_date' => '2023-01-01T12:00:00+00:00',
            // No latest version information
            'cached_at' => '2023-07-01T12:00:00+00:00',
        ];

        // Mock cache hit
        $this->mockCache
            ->expects(self::once())
            ->method('getPackageInfo')
            ->with('vendor/package', '1.0.0')
            ->willReturn($cachedData);

        $result = $this->service->enrichPackageWithReleaseInfo($package);

        $this->assertSame('vendor/package', $result->name);
        $this->assertInstanceOf(DateTimeImmutable::class, $result->releaseDate);
        $this->assertSame('2023-01-01T12:00:00+00:00', $result->releaseDate->format('c'));
        $this->assertNull($result->latestVersion);
        $this->assertNotInstanceOf(DateTimeImmutable::class, $result->latestReleaseDate);
    }

    public function testEnrichMultiplePackagesUsesCache(): void
    {
        $packages = [
            new Package('vendor/package1', '1.0.0'),
            new Package('vendor/package2', '2.0.0'),
        ];

        // First package cache hit
        $this->mockCache
            ->expects(self::exactly(2))
            ->method('getPackageInfo')
            ->willReturnMap([
                ['vendor/package1', '1.0.0', [
                    'release_date' => '2023-01-01T12:00:00+00:00',
                    'cached_at' => '2023-07-01T12:00:00+00:00',
                ]],
                ['vendor/package2', '2.0.0', null], // Cache miss
            ]);

        // Only second package should trigger API call
        $this->mockClient
            ->expects(self::once())
            ->method('getPackageInfo')
            ->with('vendor/package2')
            ->willReturn([
                'packages' => [
                    'vendor/package2' => [
                        [
                            'name' => 'vendor/package2',
                            'version' => '2.0.0',
                            'time' => '2023-02-01T12:00:00+00:00',
                        ],
                    ],
                ],
            ]);

        // Only second package should be cached
        $this->mockCache
            ->expects(self::once())
            ->method('storePackageInfo')
            ->with('vendor/package2', '2.0.0', self::isArray());

        $results = $this->service->enrichPackagesWithReleaseInfo($packages);

        $this->assertCount(2, $results);
        $this->assertEquals('vendor/package1', $results[0]->name);
        $this->assertEquals('vendor/package2', $results[1]->name);
    }
}
