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

namespace KonradMichalik\ComposerDependencyAge\Tests\Parser;

use KonradMichalik\ComposerDependencyAge\Exception\LockFileException;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Parser\LockFileParser;
use PHPUnit\Framework\TestCase;

/**
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 *
 * @package ComposerDependencyAge
 */
final class LockFileParserTest extends TestCase
{
    private LockFileParser $parser;
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->parser = new LockFileParser();
        $this->fixturesDir = __DIR__.'/../fixtures';
    }

    public function testParseFileWithValidLockFile(): void
    {
        $lockFilePath = $this->fixturesDir.'/sample-composer.lock';
        $packages = $this->parser->parseFile($lockFilePath);

        $this->assertIsArray($packages);
        $this->assertCount(3, $packages); // 2 production + 1 dev package

        // Test production packages
        $doctrine = $packages[0];
        $this->assertInstanceOf(Package::class, $doctrine);
        $this->assertSame('doctrine/orm', $doctrine->name);
        $this->assertSame('2.14.0', $doctrine->version);
        $this->assertFalse($doctrine->isDev);
        $this->assertTrue($doctrine->isProduction());

        $guzzle = $packages[1];
        $this->assertEquals('guzzlehttp/guzzle', $guzzle->name);
        $this->assertEquals('7.8.0', $guzzle->version);
        $this->assertFalse($guzzle->isDev);

        // Test dev package
        $phpunit = $packages[2];
        $this->assertEquals('phpunit/phpunit', $phpunit->name);
        $this->assertEquals('10.5.0', $phpunit->version);
        $this->assertTrue($phpunit->isDev);
        $this->assertFalse($phpunit->isProduction());
    }

    public function testParseFileWithNonExistentFile(): void
    {
        $this->expectException(LockFileException::class);
        $this->expectExceptionMessage('Lock file not found:');

        $this->parser->parseFile('/non/existent/composer.lock');
    }

    public function testParseContentWithValidContent(): void
    {
        $lockFileContent = file_get_contents($this->fixturesDir.'/sample-composer.lock');
        $packages = $this->parser->parseContent($lockFileContent);

        $this->assertCount(3, $packages);
        $this->assertEquals('doctrine/orm', $packages[0]->name);
        $this->assertEquals('guzzlehttp/guzzle', $packages[1]->name);
        $this->assertEquals('phpunit/phpunit', $packages[2]->name);
    }

    public function testParseContentWithEmptyPackages(): void
    {
        $lockFileContent = file_get_contents($this->fixturesDir.'/empty-composer.lock');
        $packages = $this->parser->parseContent($lockFileContent);

        $this->assertIsArray($packages);
        $this->assertCount(0, $packages);
    }

    public function testParseContentWithInvalidJson(): void
    {
        $this->expectException(LockFileException::class);
        $this->expectExceptionMessage('Invalid JSON in lock file');

        $this->parser->parseContent('invalid json content');
    }

    public function testParseContentWithMissingPackagesArray(): void
    {
        $this->expectException(LockFileException::class);
        $this->expectExceptionMessage('Invalid lock file format: missing packages array');

        $this->parser->parseContent('{"_readme": ["test"]}');
    }

    public function testParseContentWithNonArrayPackages(): void
    {
        $this->expectException(LockFileException::class);
        $this->expectExceptionMessage('Invalid lock file format: missing packages array');

        $this->parser->parseContent('{"packages": "not an array"}');
    }

    public function testParseProductionOnlyExcludesDevPackages(): void
    {
        $lockFileContent = file_get_contents($this->fixturesDir.'/sample-composer.lock');
        $packages = $this->parser->parseProductionOnly($lockFileContent);

        $this->assertCount(2, $packages); // Only production packages
        $this->assertEquals('doctrine/orm', $packages[0]->name);
        $this->assertEquals('guzzlehttp/guzzle', $packages[1]->name);

        foreach ($packages as $package) {
            $this->assertFalse($package->isDev);
        }
    }

    public function testParseContentWithMissingPackageName(): void
    {
        $invalidContent = json_encode([
            'packages' => [
                ['version' => '1.0.0'], // Missing name
            ],
        ]);

        $this->expectException(LockFileException::class);
        $this->expectExceptionMessage('Invalid package data: missing name');

        $this->parser->parseContent($invalidContent);
    }

    public function testParseContentWithMissingPackageVersion(): void
    {
        $invalidContent = json_encode([
            'packages' => [
                ['name' => 'test/package'], // Missing version
            ],
        ]);

        $this->expectException(LockFileException::class);
        $this->expectExceptionMessage('Invalid package data for test/package: missing version');

        $this->parser->parseContent($invalidContent);
    }

    public function testParseContentWithNonStringPackageName(): void
    {
        $invalidContent = json_encode([
            'packages' => [
                ['name' => 123, 'version' => '1.0.0'], // Invalid name type
            ],
        ]);

        $this->expectException(LockFileException::class);
        $this->expectExceptionMessage('Invalid package data: missing name');

        $this->parser->parseContent($invalidContent);
    }

    public function testParseContentWithNonStringPackageVersion(): void
    {
        $invalidContent = json_encode([
            'packages' => [
                ['name' => 'test/package', 'version' => 123], // Invalid version type
            ],
        ]);

        $this->expectException(LockFileException::class);
        $this->expectExceptionMessage('Invalid package data for test/package: missing version');

        $this->parser->parseContent($invalidContent);
    }

    public function testParseContentHandlesDevPackagesCorrectly(): void
    {
        $contentWithDevPackages = json_encode([
            'packages' => [
                ['name' => 'prod/package', 'version' => '1.0.0'],
            ],
            'packages-dev' => [
                ['name' => 'dev/package', 'version' => '2.0.0'],
            ],
        ]);

        $packages = $this->parser->parseContent($contentWithDevPackages);

        $this->assertCount(2, $packages);
        $this->assertFalse($packages[0]->isDev);
        $this->assertTrue($packages[1]->isDev);
    }

    public function testParseContentHandlesMissingDevPackages(): void
    {
        $contentWithoutDevPackages = json_encode([
            'packages' => [
                ['name' => 'prod/package', 'version' => '1.0.0'],
            ],
        ]);

        $packages = $this->parser->parseContent($contentWithoutDevPackages);

        $this->assertCount(1, $packages);
        $this->assertFalse($packages[0]->isDev);
    }

    public function testParseProductionOnlyWithInvalidJson(): void
    {
        $this->expectException(LockFileException::class);
        $this->expectExceptionMessage('Invalid JSON in lock file');

        $this->parser->parseProductionOnly('invalid json');
    }

    public function testParseProductionOnlyWithMissingPackagesArray(): void
    {
        $this->expectException(LockFileException::class);
        $this->expectExceptionMessage('Invalid lock file format: missing packages array');

        $this->parser->parseProductionOnly('{"_readme": ["test"]}');
    }

    public function testParseFileCreatesPackagesWithCorrectTypes(): void
    {
        // Additional test to ensure we have proper type checking
        $lockFilePath = $this->fixturesDir.'/sample-composer.lock';
        $packages = $this->parser->parseFile($lockFilePath);

        foreach ($packages as $package) {
            $this->assertInstanceOf(Package::class, $package);
            $this->assertIsString($package->name);
            $this->assertIsString($package->version);
            $this->assertIsBool($package->isDev);
        }
    }
}
