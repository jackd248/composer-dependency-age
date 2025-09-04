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
use Iterator;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use PHPUnit\Framework\TestCase;

/**
 * AgeCalculationServiceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class AgeCalculationServiceTest extends TestCase
{
    private AgeCalculationService $service;

    protected function setUp(): void
    {
        $this->service = new AgeCalculationService();
    }

    public function testCalculateAgeInDaysWithSpecificDates(): void
    {
        $from = new DateTimeImmutable('2023-01-01');
        $to = new DateTimeImmutable('2023-01-31');

        $age = $this->service->calculateAgeInDays($from, $to);

        $this->assertSame(30, $age);
    }

    public function testCalculateAgeInDaysWithCurrentDate(): void
    {
        $yesterday = new DateTimeImmutable('yesterday');

        $age = $this->service->calculateAgeInDays($yesterday);

        $this->assertGreaterThanOrEqual(1, $age);
        $this->assertLessThanOrEqual(2, $age); // Account for timing differences
    }

    public function testCalculateAgeInDaysWithSameDate(): void
    {
        $date = new DateTimeImmutable('2023-01-01 12:00:00');

        $age = $this->service->calculateAgeInDays($date, $date);

        $this->assertSame(0, $age);
    }

    public function testCalculateAgeInDaysWithFutureDate(): void
    {
        $from = new DateTimeImmutable('2023-01-11');
        $to = new DateTimeImmutable('2023-01-01');

        $age = $this->service->calculateAgeInDays($from, $to);

        $this->assertSame(10, $age); // DateInterval->days is always positive
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formatAgeProvider')]
    public function testFormatAge(int $days, string $expected): void
    {
        $formatted = $this->service->formatAge($days);
        $this->assertSame($expected, $formatted);
    }

    public static function formatAgeProvider(): Iterator
    {
        yield [0, 'today'];
        yield [1, '1 day'];
        yield [2, '2 days'];
        yield [7, '7 days'];
        yield [14, '14 days'];
        yield [27, '27 days'];
        yield [28, '4 weeks'];
        // 4 * 7 = 28
        yield [35, '5 weeks'];
        // ~5 weeks
        yield [42, '6 weeks'];
        // 6 * 7 = 42
        yield [55, '8 weeks'];
        // ~8 weeks
        yield [56, '2 months'];
        // 56/30.44 ≈ 1.8 → 2 months
        yield [90, '3 months'];
        // ~3 months
        yield [180, '6 months'];
        // ~6 months
        yield [365, '1.0 years'];
        // Exactly 1 year
        yield [400, '1.1 years'];
        // ~1.1 years
        yield [730, '2.0 years'];
        // 730 days = ~1.999 years (365.25 * 2 = 730.5)
        yield [800, '2.2 years'];
        // ~2.2 years
        yield [1095, '3 years'];
    }

    public function testCalculateAgeReductionWithBothDates(): void
    {
        $releaseDate = new DateTimeImmutable('2023-01-01');
        $latestReleaseDate = new DateTimeImmutable('2023-06-01');
        $referenceDate = new DateTimeImmutable('2023-07-01');

        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            releaseDate: $releaseDate,
            latestVersion: '1.5.0',
            latestReleaseDate: $latestReleaseDate,
        );

        $reduction = $this->service->calculateAgeReduction($package, $referenceDate);

        // Current version is ~6 months old, latest is ~1 month old
        // Reduction should be ~5 months = ~150 days
        $this->assertNotNull($reduction);
        $this->assertGreaterThan(140, $reduction);
        $this->assertLessThan(160, $reduction);
    }

    public function testCalculateAgeReductionWithoutReleaseDate(): void
    {
        $package = new Package('test/package', '1.0.0');

        $reduction = $this->service->calculateAgeReduction($package);

        $this->assertNull($reduction);
    }

    public function testCalculateAgeReductionWithoutLatestDate(): void
    {
        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            releaseDate: new DateTimeImmutable('2023-01-01'),
        );

        $reduction = $this->service->calculateAgeReduction($package);

        $this->assertNull($reduction);
    }

    public function testCalculateAgeReductionNeverNegative(): void
    {
        $releaseDate = new DateTimeImmutable('2023-06-01'); // Newer
        $latestReleaseDate = new DateTimeImmutable('2023-01-01'); // Older
        $referenceDate = new DateTimeImmutable('2023-07-01');

        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            releaseDate: $releaseDate,
            latestVersion: '0.9.0',
            latestReleaseDate: $latestReleaseDate,
        );

        $reduction = $this->service->calculateAgeReduction($package, $referenceDate);

        $this->assertSame(0, $reduction); // Should be 0, not negative
    }

    public function testCalculateAverageAgeWithPackages(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package(
                name: 'package/one',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2023-01-01'), // 6 months = ~180 days
            ),
            new Package(
                name: 'package/two',
                version: '2.0.0',
                releaseDate: new DateTimeImmutable('2023-04-01'), // 3 months = ~90 days
            ),
        ];

        $average = $this->service->calculateAverageAge($packages, $referenceDate);

        $this->assertNotNull($average);
        $this->assertEqualsWithDelta(135, $average, 5); // Average of ~180 and ~90
    }

    public function testCalculateAverageAgeWithEmptyArray(): void
    {
        $average = $this->service->calculateAverageAge([]);

        $this->assertNull($average);
    }

    public function testCalculateAverageAgeWithoutReleaseDates(): void
    {
        $packages = [
            new Package('package/one', '1.0.0'),
            new Package('package/two', '2.0.0'),
        ];

        $average = $this->service->calculateAverageAge($packages);

        $this->assertNull($average);
    }

    public function testCalculateAverageAgeWithMixedPackages(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package(
                name: 'package/with-date',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2023-01-01'),
            ),
            new Package('package/without-date', '2.0.0'), // No release date
        ];

        $average = $this->service->calculateAverageAge($packages, $referenceDate);

        $this->assertNotNull($average);
        $this->assertEqualsWithDelta(181, $average, 5); // Only counts package with date
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ageCategoryProvider')]
    public function testGetAgeCategory(int $days, string $expected): void
    {
        $category = $this->service->getAgeCategory($days);
        $this->assertSame($expected, $category);
    }

    public static function ageCategoryProvider(): Iterator
    {
        yield [0, 'current'];
        yield [30, 'current'];
        // 1 month
        yield [90, 'current'];
        // 3 months
        yield [182, 'current'];
        // Just under 6 months
        yield [183, 'medium'];
        // 6 months
        yield [200, 'medium'];
        // 6+ months
        yield [364, 'medium'];
        // Just under 12 months
        yield [365, 'medium'];
        // 12 months
        yield [500, 'old'];
    }

    public function testGetAgeCategoryWithCustomThresholds(): void
    {
        $customThresholds = [
            'current' => 30, // 30 days
            'medium' => 90, // 90 days
        ];

        $this->assertSame('current', $this->service->getAgeCategory(29, $customThresholds));
        $this->assertSame('current', $this->service->getAgeCategory(30, $customThresholds));  // 30 <= 30
        $this->assertSame('medium', $this->service->getAgeCategory(89, $customThresholds));
        $this->assertSame('medium', $this->service->getAgeCategory(90, $customThresholds));   // 90 <= 90
    }

    public function testCalculateStatisticsWithPackages(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package(
                name: 'package/old',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2023-01-01'), // ~180 days
                latestVersion: '1.5.0',
                latestReleaseDate: new DateTimeImmutable('2023-06-01'), // ~30 days
            ),
            new Package(
                name: 'package/newer',
                version: '2.0.0',
                releaseDate: new DateTimeImmutable('2023-05-01'), // ~60 days
                latestVersion: '2.1.0',
                latestReleaseDate: new DateTimeImmutable('2023-06-15'), // ~15 days
            ),
        ];

        $stats = $this->service->calculateStatistics($packages, $referenceDate);

        $this->assertEquals(2, $stats['count']);
        $this->assertEqualsWithDelta(120, $stats['average_age_days'], 10); // ~(180+60)/2
        $this->assertNotNull($stats['average_age_formatted']);
        $this->assertEqualsWithDelta(120, $stats['median_age_days'], 10);
        $this->assertEqualsWithDelta(181, $stats['oldest_age_days'], 5);
        $this->assertEqualsWithDelta(61, $stats['newest_age_days'], 5);
        $this->assertNotNull($stats['potential_reduction_days']);
        $this->assertGreaterThan(0, $stats['potential_reduction_days']);
    }

    public function testCalculateStatisticsWithEmptyArray(): void
    {
        $stats = $this->service->calculateStatistics([]);

        $this->assertEquals(0, $stats['count']);
        $this->assertNull($stats['average_age_days']);
        $this->assertNull($stats['average_age_formatted']);
        $this->assertNull($stats['median_age_days']);
        $this->assertNull($stats['oldest_age_days']);
        $this->assertNull($stats['newest_age_days']);
        $this->assertNull($stats['potential_reduction_days']);
    }

    public function testCalculateStatisticsWithOddNumberOfPackages(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package(
                name: 'package/one',
                version: '1.0.0',
                releaseDate: new DateTimeImmutable('2023-01-01'), // ~180 days
            ),
            new Package(
                name: 'package/two',
                version: '2.0.0',
                releaseDate: new DateTimeImmutable('2023-04-01'), // ~90 days
            ),
            new Package(
                name: 'package/three',
                version: '3.0.0',
                releaseDate: new DateTimeImmutable('2023-06-01'), // ~30 days
            ),
        ];

        $stats = $this->service->calculateStatistics($packages, $referenceDate);

        $this->assertEquals(3, $stats['count']);
        // Median of [30, 90, 180] should be 90
        $this->assertEqualsWithDelta(91, $stats['median_age_days'], 5);
    }

    public function testEdgeCaseLeapYear(): void
    {
        $from = new DateTimeImmutable('2020-01-01'); // Leap year
        $to = new DateTimeImmutable('2021-01-01');

        $age = $this->service->calculateAgeInDays($from, $to);

        $this->assertSame(366, $age); // 2020 was a leap year
    }

    public function testFormatAgeEdgeCases(): void
    {
        // Test boundary conditions
        $this->assertSame('4 weeks', $this->service->formatAge(28));
        $this->assertSame('2 months', $this->service->formatAge(56));
        $this->assertSame('1.0 years', $this->service->formatAge(365));

        // Test rounding behavior - 730 days = 1.9986 years (365.25 * 2 = 730.5)
        $this->assertSame('2.0 years', $this->service->formatAge(730)); // Close to but not exactly 2 years
        $this->assertSame('2 years', $this->service->formatAge(731)); // Exactly 2 years using 365.25 formula
        $this->assertSame('2.1 years', $this->service->formatAge(766)); // 2.1 years
    }
}
