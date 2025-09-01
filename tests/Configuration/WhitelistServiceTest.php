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

namespace KonradMichalik\ComposerDependencyAge\Tests\Configuration;

use KonradMichalik\ComposerDependencyAge\Configuration\WhitelistService;
use KonradMichalik\ComposerDependencyAge\Exception\WhitelistException;
use PHPUnit\Framework\TestCase;

/**
 * Test whitelist service functionality.
 */
final class WhitelistServiceTest extends TestCase
{
    private WhitelistService $whitelistService;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->whitelistService = new WhitelistService();
        $this->tempDir = sys_get_temp_dir().'/dependency-age-test-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testLoadFromJsonFile(): void
    {
        $jsonFile = $this->tempDir.'/whitelist.json';
        $content = [
            'description' => 'Test whitelist',
            'packages' => ['vendor/package1', 'vendor/package2'],
        ];
        file_put_contents($jsonFile, json_encode($content));

        $packages = $this->whitelistService->loadFromFile($jsonFile);

        $this->assertSame(['vendor/package1', 'vendor/package2'], $packages);
    }

    public function testLoadFromJsonArrayFile(): void
    {
        $jsonFile = $this->tempDir.'/whitelist.json';
        $content = ['vendor/package1', 'vendor/package2', 'vendor/package3'];
        file_put_contents($jsonFile, json_encode($content));

        $packages = $this->whitelistService->loadFromFile($jsonFile);

        $this->assertSame(['vendor/package1', 'vendor/package2', 'vendor/package3'], $packages);
    }

    public function testLoadFromTextFile(): void
    {
        $textFile = $this->tempDir.'/whitelist.txt';
        $content = "vendor/package1\nvendor/package2\n# This is a comment\n\nvendor/package3";
        file_put_contents($textFile, $content);

        $packages = $this->whitelistService->loadFromFile($textFile);

        $this->assertSame(['vendor/package1', 'vendor/package2', 'vendor/package3'], $packages);
    }

    public function testLoadFromNonExistentFile(): void
    {
        $this->expectException(WhitelistException::class);
        $this->expectExceptionMessage('Whitelist file does not exist');

        $this->whitelistService->loadFromFile($this->tempDir.'/non-existent.json');
    }

    public function testLoadFromUnreadableFile(): void
    {
        $unreadableFile = $this->tempDir.'/unreadable.json';
        file_put_contents($unreadableFile, '[]');
        chmod($unreadableFile, 0000);

        $this->expectException(WhitelistException::class);
        $this->expectExceptionMessage('Whitelist file is not readable');

        try {
            $this->whitelistService->loadFromFile($unreadableFile);
        } finally {
            chmod($unreadableFile, 0644); // Cleanup
        }
    }

    public function testLoadFromInvalidJsonFile(): void
    {
        $jsonFile = $this->tempDir.'/invalid.json';
        file_put_contents($jsonFile, '{invalid json}');

        $this->expectException(WhitelistException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->whitelistService->loadFromFile($jsonFile);
    }

    public function testLoadFromJsonWithoutPackages(): void
    {
        $jsonFile = $this->tempDir.'/no-packages.json';
        file_put_contents($jsonFile, '{"description": "No packages"}');

        $this->expectException(WhitelistException::class);
        $this->expectExceptionMessage('must contain \'packages\' array');

        $this->whitelistService->loadFromFile($jsonFile);
    }

    public function testLoadFromJsonWithDuplicates(): void
    {
        $jsonFile = $this->tempDir.'/duplicates.json';
        $content = ['vendor/package1', 'vendor/package2', 'vendor/package1'];
        file_put_contents($jsonFile, json_encode($content));

        $packages = $this->whitelistService->loadFromFile($jsonFile);

        // Should remove duplicates
        $this->assertSame(['vendor/package1', 'vendor/package2'], $packages);
    }

    public function testCreateDefaultWhitelist(): void
    {
        $whitelistFile = $this->tempDir.'/default-whitelist.json';

        $this->whitelistService->createDefaultWhitelist($whitelistFile);

        $this->assertFileExists($whitelistFile);

        $content = json_decode(file_get_contents($whitelistFile), true);
        $this->assertIsArray($content);
        $this->assertArrayHasKey('packages', $content);
        $this->assertContains('psr/log', $content['packages']);
        $this->assertContains('psr/container', $content['packages']);
    }

    public function testCreateDefaultWhitelistInvalidPath(): void
    {
        $invalidPath = '/invalid/path/whitelist.json';

        $this->expectException(WhitelistException::class);
        $this->expectExceptionMessage('Failed to write default whitelist file');

        $this->whitelistService->createDefaultWhitelist($invalidPath);
    }

    public function testValidatePackageNames(): void
    {
        $validPackages = ['vendor/package', 'another/package-name', 'test/package_with_underscores'];
        $errors = $this->whitelistService->validatePackageNames($validPackages);

        $this->assertEmpty($errors);
    }

    public function testValidateInvalidPackageNames(): void
    {
        $invalidPackages = [
            123,                    // Not a string
            '',                     // Empty string
            '  ',                   // Whitespace only
            'invalid-format',       // No vendor/package format
            'vendor/',              // Missing package name
            '/package',             // Missing vendor name
        ];

        $errors = $this->whitelistService->validatePackageNames($invalidPackages);

        $this->assertGreaterThan(0, count($errors));
        $this->assertContains('Invalid package name (not a string): 123', $errors);
        $this->assertContains('Empty package name found', $errors);
        $this->assertContains('Invalid package name format: invalid-format', $errors);
    }

    public function testLoadFromJsonWithInvalidPackageNames(): void
    {
        $jsonFile = $this->tempDir.'/invalid-names.json';
        $content = ['valid/package', 'invalid-format', ''];
        file_put_contents($jsonFile, json_encode($content));

        $this->expectException(WhitelistException::class);
        $this->expectExceptionMessage('Invalid package names in whitelist');

        $this->whitelistService->loadFromFile($jsonFile);
    }

    public function testLoadFromTextWithInvalidPackageNames(): void
    {
        $textFile = $this->tempDir.'/invalid-names.txt';
        $content = "valid/package\ninvalid-format\n";
        file_put_contents($textFile, $content);

        $this->expectException(WhitelistException::class);
        $this->expectExceptionMessage('Invalid package names in whitelist');

        $this->whitelistService->loadFromFile($textFile);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
