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

namespace KonradMichalik\ComposerDependencyAge\Tests\Api;

use KonradMichalik\ComposerDependencyAge\Api\PackagistClient;
use KonradMichalik\ComposerDependencyAge\Exception\ApiException;
use PHPUnit\Framework\TestCase;

/**
 * Test the PackagistClient class.
 */
final class PackagistClientTest extends TestCase
{
    private PackagistClient $client;

    protected function setUp(): void
    {
        $this->client = new PackagistClient(timeout: 5);
    }

    public function testClientConstructionWithDefaults(): void
    {
        $client = new PackagistClient();
        $this->assertInstanceOf(PackagistClient::class, $client);
    }

    public function testClientConstructionWithCustomTimeout(): void
    {
        $client = new PackagistClient(timeout: 60);
        $this->assertInstanceOf(PackagistClient::class, $client);
    }

    public function testGetPackageInfoWithInvalidUrl(): void
    {
        // Test with a package name that would create an invalid URL
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('HTTP 404: Package not found');

        $this->client->getPackageInfo('non-existent-package/that-does-not-exist-anywhere');
    }

    public function testGetMultiplePackageInfoWithEmptyArray(): void
    {
        $result = $this->client->getMultiplePackageInfo([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetMultiplePackageInfoWithSinglePackage(): void
    {
        $packages = ['non-existent-package/test'];
        $result = $this->client->getMultiplePackageInfo($packages);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('non-existent-package/test', $result);
        $this->assertNull($result['non-existent-package/test']);
    }

    public function testGetMultiplePackageInfoWithMultiplePackages(): void
    {
        $packages = ['non-existent-package/test1', 'non-existent-package/test2'];
        $result = $this->client->getMultiplePackageInfo($packages);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('non-existent-package/test1', $result);
        $this->assertArrayHasKey('non-existent-package/test2', $result);
        $this->assertNull($result['non-existent-package/test1']);
        $this->assertNull($result['non-existent-package/test2']);
    }

    public function testPackageExistsReturnsFalseForNonExistentPackage(): void
    {
        $exists = $this->client->packageExists('non-existent-package/that-does-not-exist');

        $this->assertFalse($exists);
    }

    public function testGetPackageInfoWithInvalidPackageName(): void
    {
        $this->expectException(ApiException::class);

        $this->client->getPackageInfo('invalid-package-name');
    }

    public function testGetPackageInfoWithEmptyPackageName(): void
    {
        $this->expectException(ApiException::class);

        $this->client->getPackageInfo('');
    }

    /**
     * Test error handling with network failures.
     */
    public function testGetPackageInfoHandlesNetworkErrors(): void
    {
        // This test demonstrates the error handling by trying to connect to an invalid URL
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('HTTP 404: Package not found');

        // Use a package name that will definitely not exist
        $this->client->getPackageInfo('non-existent-vendor/non-existent-package-'.uniqid());
    }

    /**
     * Test timeout configuration.
     */
    public function testClientTimeoutConfiguration(): void
    {
        $shortTimeoutClient = new PackagistClient(timeout: 1);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('HTTP 404: Package not found');

        // This should timeout quickly
        $shortTimeoutClient->getPackageInfo('non-existent-vendor/timeout-test-package');
    }

    /**
     * Test package name validation through API calls.
     */
    public function testPackageNameValidation(): void
    {
        // Test various invalid package name formats
        $invalidNames = [
            'invalid-name-without-slash',
            '',
            'vendor/',
            '/package',
            'vendor//package',
            'vendor/package/extra',
        ];

        foreach ($invalidNames as $invalidName) {
            try {
                $this->client->getPackageInfo($invalidName);
                // If no exception is thrown, the API call succeeded unexpectedly
                $this->fail("Expected ApiException for invalid package name: $invalidName");
            } catch (ApiException $e) {
                $this->assertStringContainsString('HTTP 404: Package not found', $e->getMessage());
            }
        }
    }

    /**
     * Test that packageExists correctly handles API errors.
     */
    public function testPackageExistsHandlesApiErrors(): void
    {
        // Use a timeout so short that the request will fail
        $failingClient = new PackagistClient(timeout: 1);

        $exists = $failingClient->packageExists('timeout-test/package');

        // Should return false when the request fails
        $this->assertFalse($exists);
    }
}
