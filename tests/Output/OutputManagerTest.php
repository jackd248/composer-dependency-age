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

namespace KonradMichalik\ComposerDependencyAge\Tests\Output;

use DateTimeImmutable;
use InvalidArgumentException;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Output\OutputManager;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\RatingService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Test output manager functionality.
 */
final class OutputManagerTest extends TestCase
{
    private OutputManager $outputManager;
    private DateTimeImmutable $referenceDate;
    private Package $testPackage;

    protected function setUp(): void
    {
        $ageCalculationService = new AgeCalculationService();
        $ratingService = new RatingService($ageCalculationService);

        $this->outputManager = new OutputManager($ageCalculationService, $ratingService);
        $this->referenceDate = new DateTimeImmutable('2024-01-15 12:00:00');

        $this->testPackage = new Package(
            name: 'vendor/test',
            version: '1.0.0',
            isDev: false,
            releaseDate: new DateTimeImmutable('2023-06-15 10:00:00'),
        );
    }

    public function testRenderCliTable(): void
    {
        $output = new BufferedOutput();

        $this->outputManager->renderCliTable(
            [$this->testPackage],
            $output,
            ['show_colors' => false],
            [],
            $this->referenceDate,
        );

        $result = $output->fetch();
        $this->assertIsString($result);
        $this->assertStringContainsString('vendor/test', $result);
        $this->assertStringContainsString('1.0.0', $result);
    }

    public function testFormatJson(): void
    {
        $output = $this->outputManager->format(
            'json',
            [$this->testPackage],
            [],
            [],
            $this->referenceDate,
        );

        $this->assertJson($output);

        $data = json_decode($output, true);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('packages', $data);
        $this->assertEquals('vendor/test', $data['packages'][0]['name']);
    }

    public function testFormatGitHub(): void
    {
        $output = $this->outputManager->format(
            'github',
            [$this->testPackage],
            [],
            [],
            $this->referenceDate,
        );

        $this->assertIsString($output);
        $this->assertStringContainsString('Dependency Age Report', $output);
        $this->assertStringContainsString('vendor/test', $output);
        $this->assertStringContainsString('|', $output); // Markdown table
    }

    public function testFormatUnsupportedFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported output format: invalid');

        $this->outputManager->format('invalid', [$this->testPackage]);
    }

    public function testGetStructuredData(): void
    {
        $data = $this->outputManager->getStructuredData(
            [$this->testPackage],
            [],
            $this->referenceDate,
        );

        $this->assertIsArray($data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('packages', $data);
        $this->assertEquals('vendor/test', $data['packages'][0]['name']);
    }

    public function testGetAvailableFormats(): void
    {
        $formats = OutputManager::getAvailableFormats();

        $this->assertIsArray($formats);
        $this->assertContains('cli', $formats);
        $this->assertContains('json', $formats);
        $this->assertContains('github', $formats);
    }

    public function testIsFormatSupported(): void
    {
        $this->assertTrue(OutputManager::isFormatSupported('cli'));
        $this->assertTrue(OutputManager::isFormatSupported('json'));
        $this->assertTrue(OutputManager::isFormatSupported('github'));
        $this->assertFalse(OutputManager::isFormatSupported('invalid'));
        $this->assertFalse(OutputManager::isFormatSupported('xml'));
    }

    public function testFormatWithEmptyPackages(): void
    {
        $output = new BufferedOutput();
        $this->outputManager->renderCliTable([], $output);
        $cliOutput = $output->fetch();
        $this->assertStringContainsString('No packages found', $cliOutput);

        $jsonOutput = $this->outputManager->format('json', []);
        $data = json_decode($jsonOutput, true);
        $this->assertEquals(0, $data['summary']['total_packages']);
        $this->assertEmpty($data['packages']);

        $githubOutput = $this->outputManager->format('github', []);
        $this->assertStringContainsString('_No packages found to analyze._', $githubOutput);
    }

    public function testFormatWithMultiplePackages(): void
    {
        $packages = [
            new Package(
                name: 'vendor/package1',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2023-01-15 10:00:00'),
            ),
            new Package(
                name: 'vendor/package2',
                version: '2.0.0',
                releaseDate: new DateTimeImmutable('2023-12-01 10:00:00'),
            ),
        ];

        $jsonOutput = $this->outputManager->format('json', $packages, [], [], $this->referenceDate);
        $data = json_decode($jsonOutput, true);

        $this->assertEquals(2, $data['summary']['total_packages']);
        $this->assertCount(2, $data['packages']);
    }

    public function testFormatWithCustomThresholds(): void
    {
        $thresholds = [
            'current' => 0.25,
            'medium' => 0.5,
            'old' => 1.0,
        ];

        $jsonOutput = $this->outputManager->format(
            'json',
            [$this->testPackage],
            [],
            $thresholds,
            $this->referenceDate,
        );

        $this->assertJson($jsonOutput);

        $data = json_decode($jsonOutput, true);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('packages', $data);
    }

    public function testRenderCliTableWithOptions(): void
    {
        $options = [
            'show_colors' => true,
            'custom_option' => 'value',
        ];

        $output = new BufferedOutput();
        $this->outputManager->renderCliTable(
            [$this->testPackage],
            $output,
            $options,
            [],
            $this->referenceDate,
        );

        $result = $output->fetch();
        $this->assertIsString($result);
    }

    public function testConsistentDataBetweenFormats(): void
    {
        // Get structured data
        $structuredData = $this->outputManager->getStructuredData(
            [$this->testPackage],
            [],
            $this->referenceDate,
        );

        // Get JSON output and parse it
        $jsonOutput = $this->outputManager->format('json', [$this->testPackage], [], [], $this->referenceDate);
        $jsonData = json_decode($jsonOutput, true);

        // Both should have the same structure and data
        $this->assertEquals($structuredData['summary']['total_packages'], $jsonData['summary']['total_packages']);
        $this->assertEquals($structuredData['packages'][0]['name'], $jsonData['packages'][0]['name']);
        $this->assertEquals($structuredData['packages'][0]['installed_version'], $jsonData['packages'][0]['installed_version']);
    }

    public function testAllFormatsHandleNoReleaseDate(): void
    {
        $packageWithoutDate = new Package(
            name: 'vendor/no-date',
            version: '1.0.0',
            isDev: false,
        );

        // All formats should handle packages without release dates gracefully
        $output = new BufferedOutput();
        $this->outputManager->renderCliTable([$packageWithoutDate], $output);
        $cliOutput = $output->fetch();
        $this->assertIsString($cliOutput);

        $jsonOutput = $this->outputManager->format('json', [$packageWithoutDate]);
        $this->assertJson($jsonOutput);

        $githubOutput = $this->outputManager->format('github', [$packageWithoutDate]);
        $this->assertIsString($githubOutput);
    }
}
