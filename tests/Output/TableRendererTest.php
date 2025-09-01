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
use Iterator;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Output\ColorFormatter;
use KonradMichalik\ComposerDependencyAge\Output\TableRenderer;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\RatingService;
use PHPUnit\Framework\TestCase;

/**
 * Test the TableRenderer class.
 */
final class TableRendererTest extends TestCase
{
    private TableRenderer $renderer;
    private AgeCalculationService $ageService;
    private RatingService $ratingService;

    protected function setUp(): void
    {
        $this->ageService = new AgeCalculationService();
        $this->ratingService = new RatingService($this->ageService);
        $this->renderer = new TableRenderer($this->ageService, $this->ratingService);
    }

    public function testRenderTableWithEmptyPackages(): void
    {
        $result = $this->renderer->renderTable([]);

        $this->assertSame("No packages found.\n", $result);
    }

    public function testRenderTableWithSinglePackage(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $package = new Package(
            name: 'doctrine/orm',
            version: '2.14.0',
            isDev: false,
            releaseDate: new DateTimeImmutable('2022-12-01'), // ~7 months old
            latestVersion: '2.17.2',
            latestReleaseDate: new DateTimeImmutable('2023-06-01'),
        );

        $result = $this->renderer->renderTable([$package], [
            'reference_date' => $referenceDate,
            'show_colors' => false,
        ]);

        $this->assertStringContainsString('doctrine/orm', $result);
        $this->assertStringContainsString('2.14.0', $result);
        $this->assertStringContainsString('2.17.2', $result);
        $this->assertStringContainsString('🟡 Outdated', $result);
        $this->assertStringContainsString('Update available', $result);

        // Check table structure
        $lines = explode("\n", $result);
        $this->assertCount(4, array_filter($lines)); // Header, separator, data, separator (empty line filtered out)
        $this->assertStringContainsString('|', $lines[0]); // Header row
        $this->assertStringContainsString('-', $lines[1]); // Separator
        $this->assertStringContainsString('|', $lines[2]); // Data row
    }

    public function testRenderTableWithMultiplePackages(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package(
                name: 'doctrine/orm',
                version: '2.14.0',
                isDev: false,
                releaseDate: new DateTimeImmutable('2022-12-01'), // ~7 months old
                latestVersion: '2.17.2',
                latestReleaseDate: new DateTimeImmutable('2023-06-01'),
            ),
            new Package(
                name: 'psr/log',
                version: '1.1.4',
                isDev: false,
                releaseDate: new DateTimeImmutable('2021-01-01'), // ~2.5 years old
                latestVersion: '3.0.0',
                latestReleaseDate: new DateTimeImmutable('2023-05-01'),
            ),
            new Package(
                name: 'guzzle/http',
                version: '7.8.0',
                isDev: true,
                releaseDate: new DateTimeImmutable('2023-05-01'), // ~2 months old
            ),
        ];

        $result = $this->renderer->renderTable($packages, [
            'reference_date' => $referenceDate,
            'show_colors' => false,
        ]);

        $this->assertStringContainsString('doctrine/orm', $result);
        $this->assertStringContainsString('psr/log', $result);
        $this->assertStringContainsString('guzzle/http', $result);

        // Check ratings
        $this->assertStringContainsString('🟡 Outdated', $result); // doctrine/orm
        $this->assertStringContainsString('🔴 Critical', $result); // psr/log
        $this->assertStringContainsString('🟢 Current', $result); // guzzle/http

        // Count data rows (header + separator + 3 data rows + separator = 6 lines)
        $lines = explode("\n", $result);
        $dataLines = array_filter($lines, fn ($line) => !empty($line) && !str_starts_with($line, '-'));
        $this->assertCount(4, $dataLines); // 1 header + 3 data rows
    }

    public function testRenderTableWithCustomColumns(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            isDev: true,
            releaseDate: new DateTimeImmutable('2023-05-01'),
        );

        $result = $this->renderer->renderTable([$package], [
            'columns' => ['package', 'version', 'dev'],
            'reference_date' => $referenceDate,
            'show_colors' => false,
        ]);

        $this->assertStringContainsString('Package Name', $result);
        $this->assertStringContainsString('Installed Version', $result);
        $this->assertStringContainsString('Dev Dependency', $result);
        $this->assertStringContainsString('test/package', $result);
        $this->assertStringContainsString('1.0.0', $result);
        $this->assertStringContainsString('Yes', $result);

        // Should not contain other columns
        $this->assertStringNotContainsString('Age', $result);
        $this->assertStringNotContainsString('Rating', $result);
    }

    public function testRenderTableWithColorsEnabled(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            isDev: false,
            releaseDate: new DateTimeImmutable('2023-05-01'),
        );

        $result = $this->renderer->renderTable([$package], [
            'reference_date' => $referenceDate,
            'show_colors' => true,
        ]);

        // Should contain ANSI escape codes for bold headers
        $this->assertStringContainsString("\033[1m", $result);
        $this->assertStringContainsString("\033[0m", $result);
    }

    public function testRenderTableWithColorsDisabled(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            isDev: false,
            releaseDate: new DateTimeImmutable('2023-05-01'),
        );

        $result = $this->renderer->renderTable([$package], [
            'reference_date' => $referenceDate,
            'show_colors' => false,
        ]);

        // Should not contain ANSI escape codes
        $this->assertStringNotContainsString("\033[", $result);
    }

    public function testRenderSummaryTableWithEmptyPackages(): void
    {
        $result = $this->renderer->renderSummaryTable([]);

        $this->assertSame("No packages found.\n", $result);
    }

    public function testRenderSummaryTableWithPackages(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package(
                name: 'green/package',
                version: '1.0.0',
                isDev: false,
                releaseDate: new DateTimeImmutable('2023-05-01'), // ~2 months old
            ),
            new Package(
                name: 'yellow/package',
                version: '2.0.0',
                isDev: false,
                releaseDate: new DateTimeImmutable('2022-12-01'), // ~7 months old
            ),
            new Package(
                name: 'red/package',
                version: '3.0.0',
                isDev: false,
                releaseDate: new DateTimeImmutable('2022-01-01'), // ~18 months old
            ),
        ];

        $result = $this->renderer->renderSummaryTable($packages, [
            'reference_date' => $referenceDate,
            'show_colors' => false,
        ]);

        $this->assertStringContainsString('Dependency Age Summary', $result);
        $this->assertStringContainsString('Total packages: 3', $result);
        $this->assertStringContainsString('🟢 Current: 1', $result);
        $this->assertStringContainsString('🟡 Outdated: 1', $result);
        $this->assertStringContainsString('🔴 Critical: 1', $result);
        $this->assertStringContainsString('Health score:', $result);
        $this->assertStringContainsString('Age statistics:', $result);
        $this->assertStringContainsString('Average age:', $result);
        $this->assertStringContainsString('Oldest package:', $result);
        $this->assertStringContainsString('Newest package:', $result);
    }

    public function testGetDefaultColumns(): void
    {
        $columns = $this->renderer->getDefaultColumns();

        $expectedColumns = ['package', 'version', 'age', 'rating', 'latest', 'impact', 'notes'];
        $this->assertSame($expectedColumns, $columns);
    }

    public function testGetAvailableColumns(): void
    {
        $columns = $this->renderer->getAvailableColumns();

        $this->assertIsArray($columns);
        $this->assertArrayHasKey('package', $columns);
        $this->assertArrayHasKey('version', $columns);
        $this->assertArrayHasKey('age', $columns);
        $this->assertArrayHasKey('rating', $columns);
        $this->assertArrayHasKey('latest', $columns);
        $this->assertArrayHasKey('impact', $columns);
        $this->assertArrayHasKey('notes', $columns);
        $this->assertArrayHasKey('dev', $columns);

        // Check column descriptions are meaningful
        $this->assertEquals('Package Name', $columns['package']);
        $this->assertEquals('Installed Version', $columns['version']);
        $this->assertEquals('Dev Dependency', $columns['dev']);
    }

    public function testRenderTableWithUnknownAgePackage(): void
    {
        $package = new Package('unknown/package', '1.0.0'); // No release date

        $result = $this->renderer->renderTable([$package], [
            'show_colors' => false,
        ]);

        $this->assertStringContainsString('unknown/package', $result);
        $this->assertStringContainsString('Unknown', $result);
        $this->assertStringContainsString('⚪', $result);
    }

    public function testRenderTableWithCustomThresholds(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            isDev: false,
            releaseDate: new DateTimeImmutable('2023-05-01'), // ~2 months old
        );

        // Custom thresholds: 30 days green, 90 days yellow
        $customThresholds = ['green' => 30, 'yellow' => 90];

        $result = $this->renderer->renderTable([$package], [
            'thresholds' => $customThresholds,
            'reference_date' => $referenceDate,
            'show_colors' => false,
        ]);

        // With custom thresholds, 2 months (~60 days) should be yellow
        $this->assertStringContainsString('🟡 Outdated', $result);
    }

    public function testTableFormattingConsistency(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package('short', '1.0', false, new DateTimeImmutable('2023-05-01')),
            new Package('very-long-package-name-that-should-affect-column-width', '10.0.0-beta', false, new DateTimeImmutable('2023-04-01')),
        ];

        $result = $this->renderer->renderTable($packages, [
            'reference_date' => $referenceDate,
            'show_colors' => false,
        ]);

        $lines = explode("\n", $result);
        $headerLine = $lines[0];
        $dataLine1 = $lines[2];
        $dataLine2 = $lines[3];

        // All lines should have the same structure with consistent column separators
        $headerPipes = substr_count($headerLine, '|');
        $data1Pipes = substr_count($dataLine1, '|');
        $data2Pipes = substr_count($dataLine2, '|');

        $this->assertSame($headerPipes, $data1Pipes);
        $this->assertSame($headerPipes, $data2Pipes);
        $this->assertGreaterThan(0, $headerPipes); // Should have column separators
    }

    public function testImpactFormatting(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');

        // Package with update available
        $packageWithUpdate = new Package(
            name: 'with-update/package',
            version: '1.0.0',
            isDev: false,
            releaseDate: new DateTimeImmutable('2023-01-01'), // ~6 months old
            latestVersion: '1.5.0',
            latestReleaseDate: new DateTimeImmutable('2023-06-01'), // ~1 month old
        );

        // Package without update info
        $packageWithoutUpdate = new Package(
            name: 'no-update/package',
            version: '1.0.0',
            isDev: false,
            releaseDate: new DateTimeImmutable('2023-05-01'),
        );

        $result = $this->renderer->renderTable([$packageWithUpdate, $packageWithoutUpdate], [
            'reference_date' => $referenceDate,
            'show_colors' => false,
        ]);

        // Package with update should show impact
        $this->assertStringContainsString('-', $result); // Impact column should show reduction

        // Should show meaningful impact for the package with update
        $lines = explode("\n", $result);
        $dataLine1 = $lines[2]; // First data row
        $dataLine2 = $lines[3]; // Second data row

        // Extract impact columns (index 5: Update Impact)
        $line1Columns = explode('|', $dataLine1);
        $line2Columns = explode('|', $dataLine2);

        $line1Impact = trim($line1Columns[5]);
        $line2Impact = trim($line2Columns[5]);

        // One should have months in impact column, the other should be just '-'
        $line1HasImpactMonths = str_contains($line1Impact, 'months');
        $line2HasImpactMonths = str_contains($line2Impact, 'months');

        $this->assertTrue(
            ($line1HasImpactMonths && '-' === $line2Impact) || ($line2HasImpactMonths && '-' === $line1Impact),
            "Expected one impact to contain 'months' and other to be '-'. Got: '$line1Impact' and '$line2Impact'",
        );
    }

    public function testNotesFormatting(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');

        $devPackage = new Package(
            name: 'dev/package',
            version: '1.0.0',
            isDev: true,
            releaseDate: new DateTimeImmutable('2022-01-01'), // Very old = red + critical
            latestVersion: '2.0.0',
            latestReleaseDate: new DateTimeImmutable('2023-06-01'),
        );

        $result = $this->renderer->renderTable([$devPackage], [
            'reference_date' => $referenceDate,
            'show_colors' => false,
        ]);

        $this->assertStringContainsString('Dev', $result); // Should show dev flag
        $this->assertStringContainsString('Critical', $result); // Should show critical flag
        $this->assertStringContainsString('Update available', $result); // Should show update available
    }

    public function testSetColumnWidths(): void
    {
        $result = $this->renderer->setColumnWidths(['package' => 50]);

        $this->assertInstanceOf(TableRenderer::class, $result);
        $this->assertSame($this->renderer, $result); // Should return same instance for fluent interface
    }

    /**
     * @param array<string, mixed> $packages
     * @param array<string, mixed> $columns
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('columnWidthProvider')]
    public function testColumnWidthCalculation(array $packages, array $columns, string $expectedContent): void
    {
        $result = $this->renderer->renderTable($packages, [
            'columns' => $columns,
            'show_colors' => false,
        ]);

        $this->assertStringContainsString($expectedContent, $result);

        // Ensure proper table formatting
        $lines = explode("\n", $result);
        $nonEmptyLines = array_filter($lines, fn ($line) => !empty($line));
        $this->assertGreaterThan(2, count($nonEmptyLines)); // Should have header + separator + data
    }

    public static function columnWidthProvider(): Iterator
    {
        yield 'short content' => [
            [new Package('a', 'v1')],
            ['package', 'version'],
            'Package Name',
        ];
        yield 'long content' => [
            [new Package('very-long-package-name-for-testing-column-width-calculation', 'v1.0.0-beta-release')],
            ['package', 'version'],
            'very-long-package-name-for-testing-column-width-calculation',
        ];
    }

    public function testTableStructureIntegrity(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package('test1/package', '1.0.0', false, new DateTimeImmutable('2023-05-01')),
            new Package('test2/package', '2.0.0', true, new DateTimeImmutable('2023-04-01')),
        ];

        $result = $this->renderer->renderTable($packages, [
            'reference_date' => $referenceDate,
            'show_colors' => false,
        ]);

        $lines = explode("\n", $result);

        // Should have: header, separator, data1, data2, separator, empty
        $this->assertCount(6, $lines);

        // Header line should have column names
        $this->assertStringContainsString('Package Name', $lines[0]);

        // Separator lines should contain dashes
        $this->assertStringContainsString('-', $lines[1]);
        $this->assertStringContainsString('-', $lines[4]);

        // Data lines should contain package info
        $this->assertStringContainsString('test1/package', $lines[2]);
        $this->assertStringContainsString('test2/package', $lines[3]);

        // Last line should be empty
        $this->assertSame('', $lines[5]);
    }

    public function testTableWithColorFormatter(): void
    {
        $colorFormatter = new ColorFormatter(true);
        $renderer = new TableRenderer($this->ageService, $this->ratingService, $colorFormatter);

        $referenceDate = new DateTimeImmutable('2023-07-01');
        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            isDev: false,
            releaseDate: new DateTimeImmutable('2023-05-01'),
        );

        $result = $renderer->renderTable([$package], [
            'reference_date' => $referenceDate,
            'show_colors' => true,
        ]);

        // Should contain ANSI color codes from ColorFormatter
        $this->assertStringContainsString("\033[", $result);
        $this->assertStringContainsString('test/package', $result);

        // Should have colored rating
        $this->assertStringContainsString('🟢', $result);
    }

    public function testSummaryWithColorFormatter(): void
    {
        $colorFormatter = new ColorFormatter(true);
        $renderer = new TableRenderer($this->ageService, $this->ratingService, $colorFormatter);

        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package('test/package', '1.0.0', false, new DateTimeImmutable('2023-05-01')),
        ];

        $result = $renderer->renderSummaryTable($packages, [
            'reference_date' => $referenceDate,
            'show_colors' => true,
        ]);

        // Should contain ANSI color codes for header formatting
        $this->assertStringContainsString("\033[", $result);
        $this->assertStringContainsString('Dependency Age Summary', $result);
        $this->assertStringContainsString('Total packages: 1', $result);
    }
}
