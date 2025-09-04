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
use KonradMichalik\ComposerDependencyAge\Output\JsonFormatter;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\RatingService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 *
 * @package ComposerDependencyAge
 */
final class JsonFormatterTest extends TestCase
{
    private JsonFormatter $jsonFormatter;
    private DateTimeImmutable $referenceDate;

    protected function setUp(): void
    {
        $ageCalculationService = new AgeCalculationService();
        $ratingService = new RatingService($ageCalculationService);

        $this->jsonFormatter = new JsonFormatter($ageCalculationService, $ratingService);
        $this->referenceDate = new DateTimeImmutable('2024-01-15 12:00:00');
    }

    public function testFormatEmptyPackages(): void
    {
        $json = $this->jsonFormatter->format([], [], $this->referenceDate);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('packages', $data);
        $this->assertEquals(0, $data['summary']['total_packages']);
        $this->assertEmpty($data['packages']);
    }

    public function testFormatSinglePackage(): void
    {
        $releaseDate = new DateTimeImmutable('2023-06-15 10:00:00');
        $latestReleaseDate = new DateTimeImmutable('2023-12-01 14:00:00');

        $package = new Package(
            name: 'vendor/package',
            version: '1.0.0',
            isDev: false,
            releaseDate: $releaseDate,
            latestVersion: '1.2.0',
            latestReleaseDate: $latestReleaseDate,
        );

        $json = $this->jsonFormatter->format([$package], [], $this->referenceDate);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertEquals(1, $data['summary']['total_packages']);
        $this->assertCount(1, $data['packages']);

        $packageData = $data['packages'][0];
        $this->assertEquals('vendor/package', $packageData['name']);
        $this->assertEquals('1.0.0', $packageData['installed_version']);
        $this->assertEquals($releaseDate->format('c'), $packageData['installed_release_date']);
        $this->assertIsInt($packageData['age_days']);
        $this->assertIsString($packageData['age_formatted']);
        $this->assertContains($packageData['rating'], ['current', 'medium', 'old']);
        $this->assertEquals('1.2.0', $packageData['latest_version']);
        $this->assertEquals($latestReleaseDate->format('c'), $packageData['latest_release_date']);
        $this->assertIsInt($packageData['latest_age_days']);
        $this->assertIsInt($packageData['age_reduction_days']);
        $this->assertIsString($packageData['age_reduction_formatted']);
    }

    public function testFormatPackageWithoutLatestVersion(): void
    {
        $releaseDate = new DateTimeImmutable('2022-01-15 10:00:00');

        $package = new Package(
            name: 'vendor/old-package',
            version: '0.5.0',
            isDev: false,
            releaseDate: $releaseDate,
        );

        $json = $this->jsonFormatter->format([$package], [], $this->referenceDate);
        $data = json_decode($json, true);

        $packageData = $data['packages'][0];
        $this->assertEquals('vendor/old-package', $packageData['name']);
        $this->assertEquals('0.5.0', $packageData['installed_version']);
        $this->assertNull($packageData['latest_version']);
        $this->assertNull($packageData['latest_release_date']);
        $this->assertNull($packageData['latest_age_days']);
        $this->assertNull($packageData['age_reduction_days']);
        $this->assertNull($packageData['age_reduction_formatted']);
    }

    public function testFormatPackageWithoutReleaseDate(): void
    {
        $package = new Package(
            name: 'vendor/unknown',
            version: '1.0.0',
            isDev: false,
        );

        $json = $this->jsonFormatter->format([$package], [], $this->referenceDate);
        $data = json_decode($json, true);

        $packageData = $data['packages'][0];
        $this->assertEquals('vendor/unknown', $packageData['name']);
        $this->assertNull($packageData['installed_release_date']);
        $this->assertNull($packageData['age_days']);
        $this->assertNull($packageData['age_formatted']);
        $this->assertEquals('unknown', $packageData['rating']);
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

        $json = $this->jsonFormatter->format($packages, [], $this->referenceDate);
        $data = json_decode($json, true);

        $this->assertEquals(3, $data['summary']['total_packages']);
        $this->assertCount(3, $data['packages']);

        // Check ratings
        $ratings = array_column($data['packages'], 'rating', 'name');
        $this->assertEquals('current', $ratings['vendor/fresh']);
        $this->assertEquals('medium', $ratings['vendor/aging']);
        $this->assertEquals('old', $ratings['vendor/critical']);
    }

    public function testFormatAsArray(): void
    {
        $package = new Package(
            name: 'vendor/test',
            version: '1.0.0',
            releaseDate: new DateTimeImmutable('2023-06-15 10:00:00'),
        );

        $data = $this->jsonFormatter->formatAsArray([$package], [], $this->referenceDate);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('packages', $data);
    }

    public function testSummaryCalculation(): void
    {
        $packages = [
            new Package(
                name: 'vendor/fresh',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2024-01-01 10:00:00'),
                latestVersion: '1.1.0',
                latestReleaseDate: new DateTimeImmutable('2024-01-10 10:00:00'),
            ),
            new Package(
                name: 'vendor/old',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2022-01-15 10:00:00'),
                latestVersion: '2.0.0',
                latestReleaseDate: new DateTimeImmutable('2023-12-01 10:00:00'),
            ),
        ];

        $json = $this->jsonFormatter->format($packages, [], $this->referenceDate);
        $data = json_decode($json, true);

        $summary = $data['summary'];
        $this->assertEquals(2, $summary['total_packages']);
        $this->assertIsInt($summary['average_age_days']);
        $this->assertIsString($summary['average_age_formatted']);
        $this->assertIsInt($summary['potential_age_reduction_days']);
        $this->assertIsString($summary['potential_age_reduction_formatted']);
        $this->assertIsNumeric($summary['health_score']);
        $this->assertIsBool($summary['has_critical']);
    }

    public function testInvalidJsonEncoding(): void
    {
        // Create a mock that will cause json_encode to fail
        $packages = [
            new Package(
                name: "\xB1\x31", // Invalid UTF-8 sequence
                version: '1.0.0',
            ),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to encode dependency age data as JSON');

        // This should trigger the JSON encoding error
        $formatter = new JsonFormatter(
            new AgeCalculationService(),
            new RatingService(new AgeCalculationService()),
        );

        $formatter->format($packages, [], $this->referenceDate);
    }

    public function testValidJsonStructure(): void
    {
        $package = new Package(
            name: 'vendor/test',
            version: '1.0.0',
            releaseDate: new DateTimeImmutable('2023-06-15 10:00:00'),
        );

        $json = $this->jsonFormatter->format([$package], [], $this->referenceDate);

        // Validate that it's valid JSON
        $this->assertJson($json);

        // Validate JSON structure
        $data = json_decode($json, true);
        $this->assertIsArray($data);

        // Validate required fields
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('packages', $data);

        $summary = $data['summary'];
        $requiredSummaryFields = [
            'total_packages', 'average_age_days', 'average_age_formatted',
            'critical_count', 'potential_age_reduction_days',
            'potential_age_reduction_formatted', 'health_score', 'has_critical',
        ];

        foreach ($requiredSummaryFields as $field) {
            $this->assertArrayHasKey($field, $summary, "Summary missing field: {$field}");
        }

        $packageData = $data['packages'][0];
        $requiredPackageFields = [
            'name', 'installed_version', 'installed_release_date', 'age_days',
            'age_formatted', 'rating', 'latest_version', 'latest_release_date',
            'latest_age_days', 'latest_age_formatted', 'age_reduction_days',
            'age_reduction_formatted',
        ];

        foreach ($requiredPackageFields as $field) {
            $this->assertArrayHasKey($field, $packageData, "Package missing field: {$field}");
        }
    }
}
