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
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Output\GitHubFormatter;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\RatingService;
use PHPUnit\Framework\TestCase;

/**
 * GitHubFormatterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class GitHubFormatterTest extends TestCase
{
    private GitHubFormatter $gitHubFormatter;
    private DateTimeImmutable $referenceDate;

    protected function setUp(): void
    {
        $ageCalculationService = new AgeCalculationService();
        $ratingService = new RatingService($ageCalculationService);

        $this->gitHubFormatter = new GitHubFormatter($ageCalculationService, $ratingService);
        $this->referenceDate = new DateTimeImmutable('2024-01-15 12:00:00');
    }

    public function testFormatEmptyPackages(): void
    {
        $markdown = $this->gitHubFormatter->format([], [], $this->referenceDate);

        $this->assertStringContainsString('Dependency Age Report', $markdown);
        $this->assertStringContainsString('_No packages found to analyze._', $markdown);
    }

    public function testFormatSinglePackage(): void
    {
        $releaseDate = new DateTimeImmutable('2023-06-15 10:00:00');

        $package = new Package(
            name: 'vendor/package',
            version: '1.0.0',
            isDev: false,
            releaseDate: $releaseDate,
            latestVersion: '1.2.0',
            latestReleaseDate: new DateTimeImmutable('2023-12-01 14:00:00'),
        );

        $markdown = $this->gitHubFormatter->format([$package], [], $this->referenceDate);

        // Check header (emoji varies based on health score)
        $this->assertStringContainsString('Dependency Age Report', $markdown);

        // Check summary table
        $this->assertStringContainsString('### 📊 Summary', $markdown);
        $this->assertStringContainsString('| **Total Packages** | 1 |', $markdown);
        $this->assertStringContainsString('| **Health Score**', $markdown);
        $this->assertStringContainsString('| **Average Age**', $markdown);

        // Check package table
        $this->assertStringContainsString('### 📋 Package Details', $markdown);
        $this->assertStringContainsString('| Package | Installed | Age | Rating | Latest | Improvement |', $markdown);
        $this->assertStringContainsString('`vendor/package`', $markdown);
        $this->assertStringContainsString('`1.0.0`', $markdown);
        $this->assertStringContainsString('`1.2.0`', $markdown);
    }

    public function testFormatMultiplePackagesWithDifferentRatings(): void
    {
        $packages = [
            new Package(
                name: 'vendor/fresh',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2024-01-01 10:00:00'), // ~2 weeks old
            ),
            new Package(
                name: 'vendor/aging',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2023-06-15 10:00:00'), // ~7 months old
            ),
            new Package(
                name: 'vendor/critical',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2022-01-15 10:00:00'), // ~2 years old
            ),
        ];

        $markdown = $this->gitHubFormatter->format($packages, [], $this->referenceDate);

        // Should contain all packages
        $this->assertStringContainsString('vendor/fresh', $markdown);
        $this->assertStringContainsString('vendor/aging', $markdown);
        $this->assertStringContainsString('vendor/critical', $markdown);

        // Should show different rating emojis
        $this->assertStringContainsString('🟢 Fresh', $markdown);
        $this->assertStringContainsString('🟡 Aging', $markdown);
        $this->assertStringContainsString('🔴 Critical', $markdown);

        // Should show total packages count
        $this->assertStringContainsString('| **Total Packages** | 3 |', $markdown);
    }

    public function testFormatPackageWithLatestVersion(): void
    {
        $package = new Package(
            name: 'vendor/updatable',
            version: '1.0.0',
            releaseDate: new DateTimeImmutable('2023-01-15 10:00:00'),
            latestVersion: '1.5.0',
            latestReleaseDate: new DateTimeImmutable('2023-12-01 10:00:00'),
        );

        $markdown = $this->gitHubFormatter->format([$package], [], $this->referenceDate);

        $this->assertStringContainsString('`1.5.0`', $markdown);
        $this->assertStringContainsString('📈', $markdown); // Improvement indicator
    }

    public function testFormatPackageWithSameVersion(): void
    {
        $package = new Package(
            name: 'vendor/latest',
            version: '2.0.0',
            releaseDate: new DateTimeImmutable('2023-12-01 10:00:00'),
            latestVersion: '2.0.0',
            latestReleaseDate: new DateTimeImmutable('2023-12-01 10:00:00'),
        );

        $markdown = $this->gitHubFormatter->format([$package], [], $this->referenceDate);

        $this->assertStringContainsString('✅ Latest', $markdown);
    }

    public function testFormatPackageWithoutLatestVersion(): void
    {
        $package = new Package(
            name: 'vendor/unknown',
            version: '1.0.0',
            releaseDate: new DateTimeImmutable('2023-06-15 10:00:00'),
        );

        $markdown = $this->gitHubFormatter->format([$package], [], $this->referenceDate);

        // Should have em-dash for unknown latest version and improvement
        $lines = explode("\n", $markdown);
        $packageLine = '';
        foreach ($lines as $line) {
            if (str_contains($line, 'vendor/unknown')) {
                $packageLine = $line;
                break;
            }
        }

        $this->assertNotEmpty($packageLine);
        $this->assertStringContainsString('—', $packageLine); // Em-dash for unknown values
    }

    public function testFormatWithCriticalPackages(): void
    {
        $packages = [
            new Package(
                name: 'vendor/critical1',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2021-01-15 10:00:00'), // Very old
            ),
            new Package(
                name: 'vendor/critical2',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2020-01-15 10:00:00'), // Very old
            ),
        ];

        $markdown = $this->gitHubFormatter->format($packages, [], $this->referenceDate);

        // Should show critical warning
        $this->assertStringContainsString('⚠️ Warning', $markdown);
        $this->assertStringContainsString('Critical packages found', $markdown);
        // Note: Critical packages count is not shown in the summary table in current implementation
    }

    public function testHealthScoreEmojis(): void
    {
        // Test high health score (fresh package)
        $freshPackage = new Package(
            name: 'vendor/fresh',
            version: '1.0.0',
            releaseDate: new DateTimeImmutable('2024-01-10 10:00:00'),
        );

        $markdown = $this->gitHubFormatter->format([$freshPackage], [], $this->referenceDate);
        $this->assertStringContainsString('🟢', $markdown); // Should show green emoji in header

        // Test low health score (old packages)
        $oldPackages = [
            new Package(
                name: 'vendor/old1',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2020-01-15 10:00:00'),
            ),
            new Package(
                name: 'vendor/old2',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2021-01-15 10:00:00'),
            ),
        ];

        $markdown = $this->gitHubFormatter->format($oldPackages, [], $this->referenceDate);
        $this->assertStringContainsString('🔴', $markdown); // Should show red emoji in header
    }

    public function testMarkdownEscaping(): void
    {
        $package = new Package(
            name: 'vendor/package|with|pipes',
            version: '1.0.0|beta',
            releaseDate: new DateTimeImmutable('2023-06-15 10:00:00'),
        );

        $markdown = $this->gitHubFormatter->format([$package], [], $this->referenceDate);

        // Pipes should be escaped
        $this->assertStringContainsString('vendor/package\\|with\\|pipes', $markdown);
        $this->assertStringContainsString('1.0.0\\|beta', $markdown);
    }

    public function testPackagesSortedByAge(): void
    {
        $packages = [
            new Package(
                name: 'vendor/newer',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2023-12-01 10:00:00'),
            ),
            new Package(
                name: 'vendor/older',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2022-01-15 10:00:00'),
            ),
            new Package(
                name: 'vendor/newest',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2024-01-01 10:00:00'),
            ),
        ];

        $markdown = $this->gitHubFormatter->format($packages, [], $this->referenceDate);

        // Find the positions of each package in the output
        $olderPos = strpos($markdown, 'vendor/older');
        $newerPos = strpos($markdown, 'vendor/newer');
        $newestPos = strpos($markdown, 'vendor/newest');

        // Oldest should appear first (descending order by age)
        $this->assertLessThan($newerPos, $olderPos);
        $this->assertLessThan($newestPos, $newerPos);
    }

    public function testValidMarkdownStructure(): void
    {
        $package = new Package(
            name: 'vendor/test',
            version: '1.0.0',
            releaseDate: new DateTimeImmutable('2023-06-15 10:00:00'),
        );

        $markdown = $this->gitHubFormatter->format([$package], [], $this->referenceDate);

        // Check basic Markdown structure
        $this->assertStringContainsString('##', $markdown); // Header
        $this->assertStringContainsString('###', $markdown); // Subheader
        $this->assertStringContainsString('|', $markdown); // Table
        $this->assertStringContainsString('---', $markdown); // Footer separator

        // Check table structure
        $this->assertStringContainsString('| Package | Installed | Age | Rating | Latest | Improvement |', $markdown);
        $this->assertStringContainsString('|---------|-----------|-----|--------|--------|--------------', $markdown);
    }
}
