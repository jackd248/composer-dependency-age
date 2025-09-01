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
use KonradMichalik\ComposerDependencyAge\Exception\ApiException;
use KonradMichalik\ComposerDependencyAge\Exception\PackageInfoException;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Service\PackageInfoService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test the PackageInfoService class.
 */
final class PackageInfoServiceTest extends TestCase
{
    private PackageInfoService $service;
    private PackagistClient&MockObject $mockClient;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(PackagistClient::class);
        $this->service = new PackageInfoService($this->mockClient);
    }

    public function testEnrichPackageWithReleaseInfoSuccess(): void
    {
        $package = new Package('doctrine/orm', '2.14.0');

        $apiResponse = [
            'packages' => [
                'doctrine/orm' => [
                    [
                        'name' => 'doctrine/orm',
                        'version' => '2.16.0',
                        'time' => '2023-11-10T10:15:00+00:00',
                    ],
                    [
                        'name' => 'doctrine/orm',
                        'version' => '2.15.0',
                        'time' => '2023-07-20T14:30:00+00:00',
                    ],
                    [
                        'name' => 'doctrine/orm',
                        'version' => '2.14.0',
                        'time' => '2023-04-15T09:20:00+00:00',
                    ],
                ],
            ],
        ];

        $this->mockClient->expects(self::once())
            ->method('getPackageInfo')
            ->with('doctrine/orm')
            ->willReturn($apiResponse);

        $enrichedPackage = $this->service->enrichPackageWithReleaseInfo($package);

        $this->assertSame('doctrine/orm', $enrichedPackage->name);
        $this->assertSame('2.14.0', $enrichedPackage->version);
        $this->assertInstanceOf(DateTimeImmutable::class, $enrichedPackage->releaseDate);
        $this->assertSame('2023-04-15T09:20:00+00:00', $enrichedPackage->releaseDate->format('c'));
        $this->assertSame('2.16.0', $enrichedPackage->latestVersion);
        $this->assertInstanceOf(DateTimeImmutable::class, $enrichedPackage->latestReleaseDate);
        $this->assertSame('2023-11-10T10:15:00+00:00', $enrichedPackage->latestReleaseDate->format('c'));
    }

    public function testEnrichPackageWithReleaseInfoPackageNotFound(): void
    {
        $package = new Package('non-existent/package', '1.0.0');

        $apiResponse = [
            'packages' => [], // Empty packages array
        ];

        $this->mockClient->expects(self::once())
            ->method('getPackageInfo')
            ->with('non-existent/package')
            ->willReturn($apiResponse);

        $this->expectException(PackageInfoException::class);
        $this->expectExceptionMessage("Package 'non-existent/package' not found in Packagist response");

        $this->service->enrichPackageWithReleaseInfo($package);
    }

    public function testEnrichPackageWithReleaseInfoVersionNotFound(): void
    {
        $package = new Package('doctrine/orm', '999.999.999');

        $apiResponse = [
            'packages' => [
                'doctrine/orm' => [
                    [
                        'name' => 'doctrine/orm',
                        'version' => '2.16.0',
                        'time' => '2023-11-10T10:15:00+00:00',
                    ],
                ],
            ],
        ];

        $this->mockClient->expects(self::once())
            ->method('getPackageInfo')
            ->with('doctrine/orm')
            ->willReturn($apiResponse);

        $this->expectException(PackageInfoException::class);
        $this->expectExceptionMessage("Version '999.999.999' not found for package 'doctrine/orm'");

        $this->service->enrichPackageWithReleaseInfo($package);
    }

    public function testEnrichPackageWithReleaseInfoApiException(): void
    {
        $package = new Package('test/package', '1.0.0');

        $this->mockClient->expects(self::once())
            ->method('getPackageInfo')
            ->with('test/package')
            ->willThrowException(new ApiException('API Error'));

        $this->expectException(PackageInfoException::class);
        $this->expectExceptionMessage("Failed to get package info for 'test/package': API Error");

        $this->service->enrichPackageWithReleaseInfo($package);
    }

    public function testEnrichPackageWithMissingTimeField(): void
    {
        $package = new Package('test/package', '1.0.0');

        $apiResponse = [
            'packages' => [
                'test/package' => [
                    [
                        'name' => 'test/package',
                        'version' => '1.0.0',
                        // Missing 'time' field
                    ],
                ],
            ],
        ];

        $this->mockClient->expects(self::once())
            ->method('getPackageInfo')
            ->with('test/package')
            ->willReturn($apiResponse);

        $enrichedPackage = $this->service->enrichPackageWithReleaseInfo($package);

        // Should still succeed but without release date
        $this->assertSame('test/package', $enrichedPackage->name);
        $this->assertNotInstanceOf(DateTimeImmutable::class, $enrichedPackage->releaseDate);
    }

    public function testEnrichPackagesWithReleaseInfoSuccess(): void
    {
        $packages = [
            new Package('package/one', '1.0.0'),
            new Package('package/two', '2.0.0'),
        ];

        $this->mockClient->expects(self::exactly(2))
            ->method('getPackageInfo')
            ->willReturnCallback(fn (string $packageName): array => [
                'packages' => [
                    $packageName => [
                        [
                            'name' => $packageName,
                            'version' => 'package/one' === $packageName ? '1.0.0' : '2.0.0',
                            'time' => '2023-01-01T12:00:00+00:00',
                        ],
                    ],
                ],
            ]);

        $enrichedPackages = $this->service->enrichPackagesWithReleaseInfo($packages);

        $this->assertCount(2, $enrichedPackages);
        $this->assertEquals('package/one', $enrichedPackages[0]->name);
        $this->assertEquals('package/two', $enrichedPackages[1]->name);
        $this->assertInstanceOf(DateTimeImmutable::class, $enrichedPackages[0]->releaseDate);
        $this->assertInstanceOf(DateTimeImmutable::class, $enrichedPackages[1]->releaseDate);
    }

    public function testEnrichPackagesWithReleaseInfoSkipsFailedPackages(): void
    {
        $packages = [
            new Package('package/one', '1.0.0'),
            new Package('package/failing', '2.0.0'),
        ];

        $this->mockClient->expects(self::exactly(2))
            ->method('getPackageInfo')
            ->willReturnCallback(function (string $packageName): array {
                if ('package/failing' === $packageName) {
                    throw new ApiException('API failure');
                }

                return [
                    'packages' => [
                        $packageName => [
                            [
                                'name' => $packageName,
                                'version' => '1.0.0',
                                'time' => '2023-01-01T12:00:00+00:00',
                            ],
                        ],
                    ],
                ];
            });

        // Should not throw exception, but skip failed packages
        $enrichedPackages = $this->service->enrichPackagesWithReleaseInfo($packages);

        // Should return 2 packages: one enriched, one original
        $this->assertCount(2, $enrichedPackages);
        
        // First package should be enriched
        $this->assertEquals('package/one', $enrichedPackages[0]->name);
        $this->assertInstanceOf(DateTimeImmutable::class, $enrichedPackages[0]->releaseDate);
        
        // Second package should be original (not enriched due to API failure)
        $this->assertEquals('package/failing', $enrichedPackages[1]->name);
        $this->assertNull($enrichedPackages[1]->releaseDate);
    }

    public function testFindLatestStableVersionWithStableVersions(): void
    {
        $package = new Package('test/package', '1.0.0');

        $apiResponse = [
            'packages' => [
                'test/package' => [
                    [
                        'name' => 'test/package',
                        'version' => '2.0.0-beta',
                        'time' => '2023-12-01T12:00:00+00:00',
                    ],
                    [
                        'name' => 'test/package',
                        'version' => '1.5.0',
                        'time' => '2023-11-01T12:00:00+00:00',
                    ],
                    [
                        'name' => 'test/package',
                        'version' => '1.0.0',
                        'time' => '2023-01-01T12:00:00+00:00',
                    ],
                ],
            ],
        ];

        $this->mockClient->expects(self::once())
            ->method('getPackageInfo')
            ->with('test/package')
            ->willReturn($apiResponse);

        $enrichedPackage = $this->service->enrichPackageWithReleaseInfo($package);

        // Should pick 1.5.0 as latest stable (skipping 2.0.0-beta)
        $this->assertSame('1.5.0', $enrichedPackage->latestVersion);
    }

    public function testFindLatestStableVersionWithoutStableVersions(): void
    {
        $package = new Package('test/package', '1.0.0-dev');

        $apiResponse = [
            'packages' => [
                'test/package' => [
                    [
                        'name' => 'test/package',
                        'version' => '2.0.0-dev',
                        'time' => '2023-12-01T12:00:00+00:00',
                    ],
                    [
                        'name' => 'test/package',
                        'version' => '1.0.0-dev',
                        'time' => '2023-01-01T12:00:00+00:00',
                    ],
                ],
            ],
        ];

        $this->mockClient->expects(self::once())
            ->method('getPackageInfo')
            ->with('test/package')
            ->willReturn($apiResponse);

        $enrichedPackage = $this->service->enrichPackageWithReleaseInfo($package);

        // Should fall back to first version (2.0.0-dev) since no stable versions exist
        $this->assertSame('2.0.0-dev', $enrichedPackage->latestVersion);
    }

    public function testIsStableVersionDetection(): void
    {
        // Create a test service that exposes the private method for testing
        $testService = new class($this->mockClient) extends PackageInfoService {
            public function testIsStableVersion(string $version): bool
            {
                return $this->isStableVersion($version);
            }
        };

        // Test stable versions
        $this->assertTrue($testService->testIsStableVersion('1.0.0'));
        $this->assertTrue($testService->testIsStableVersion('2.15.3'));
        $this->assertTrue($testService->testIsStableVersion('v3.1.4'));

        // Test unstable versions
        $this->assertFalse($testService->testIsStableVersion('1.0.0-dev'));
        $this->assertFalse($testService->testIsStableVersion('2.0.0-alpha'));
        $this->assertFalse($testService->testIsStableVersion('3.0.0-beta'));
        $this->assertFalse($testService->testIsStableVersion('1.0.0-rc1'));
        $this->assertFalse($testService->testIsStableVersion('2.0.0-pre'));
        $this->assertFalse($testService->testIsStableVersion('1.0-snapshot'));
    }

    public function testEnrichPackageWithReleaseInfoEmptyVersionsList(): void
    {
        $package = new Package('test/package', '1.0.0');

        $apiResponse = [
            'packages' => [
                'test/package' => [], // Empty versions array
            ],
        ];

        $this->mockClient->expects(self::once())
            ->method('getPackageInfo')
            ->with('test/package')
            ->willReturn($apiResponse);

        $this->expectException(PackageInfoException::class);
        $this->expectExceptionMessage("Version '1.0.0' not found for package 'test/package'");

        $this->service->enrichPackageWithReleaseInfo($package);
    }
}
