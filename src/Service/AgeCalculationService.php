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

namespace KonradMichalik\ComposerDependencyAge\Service;

use DateTimeImmutable;
use KonradMichalik\ComposerDependencyAge\Model\Package;

/**
 * AgeCalculationService.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
class AgeCalculationService
{
    /**
     * Calculate age in days between two dates.
     */
    public function calculateAgeInDays(DateTimeImmutable $from, ?DateTimeImmutable $to = null): int
    {
        $to ??= new DateTimeImmutable();
        $diff = $to->diff($from);

        return false === $diff->days ? 0 : $diff->days;
    }

    /**
     * Format age in days to human-readable string.
     */
    public function formatAge(int $ageInDays): string
    {
        if (0 === $ageInDays) {
            return 'today';
        }

        if (1 === $ageInDays) {
            return '1 day';
        }

        // Less than 4 weeks: show in days
        if ($ageInDays < 28) {
            return "{$ageInDays} days";
        }

        // Less than 8 weeks: show in weeks
        if ($ageInDays < 56) {
            $weeks = round($ageInDays / 7);

            return 1 === (int) $weeks ? '1 week' : "{$weeks} weeks";
        }

        // Less than 12 months: show in months
        if ($ageInDays < 365) {
            $months = round($ageInDays / 30.44); // Average days per month

            return 1 === (int) $months ? '1 month' : "{$months} months";
        }

        // 12 months or more: show in years with one decimal
        $years = $ageInDays / 365.25; // Account for leap years

        if ($years < 2) {
            return number_format($years, 1).' years';
        }

        // For 2+ years, show without decimal if it's close to a whole number
        if (abs($years - round($years)) < 0.05) {
            return round($years).' years';
        }

        return number_format($years, 1).' years';
    }

    /**
     * Calculate age reduction potential if updated to latest version.
     */
    public function calculateAgeReduction(Package $package, ?DateTimeImmutable $referenceDate = null): ?int
    {
        if (null === $package->releaseDate || null === $package->latestReleaseDate) {
            return null;
        }

        $currentAge = $this->calculateAgeInDays($package->releaseDate, $referenceDate);
        $latestAge = $this->calculateAgeInDays($package->latestReleaseDate, $referenceDate);

        $reduction = $currentAge - $latestAge;

        return max(0, $reduction); // Never negative
    }

    /**
     * Calculate average age for a collection of packages.
     *
     * @param array<Package> $packages
     */
    public function calculateAverageAge(array $packages, ?DateTimeImmutable $referenceDate = null): ?float
    {
        $totalDays = 0;
        $packageCount = 0;

        foreach ($packages as $package) {
            if (null === $package->releaseDate) {
                continue;
            }

            $age = $this->calculateAgeInDays($package->releaseDate, $referenceDate);
            $totalDays += $age;
            ++$packageCount;
        }

        if (0 === $packageCount) {
            return null;
        }

        return $totalDays / $packageCount;
    }

    /**
     * Get age category based on thresholds.
     *
     * @param array<string, mixed> $thresholds
     */
    public function getAgeCategory(int $ageInDays, array $thresholds = []): string
    {
        // Default thresholds in days (6 months, 12 months)
        $defaultThresholds = [
            'current' => 182,  // 0.5 years = ~182 days
            'medium' => 365,   // 1.0 year = 365 days
        ];

        // Convert thresholds from years to days if they appear to be in years (< 10)
        $convertedThresholds = [];
        foreach ($thresholds as $key => $value) {
            if ($value < 10) { // Assume values < 10 are in years
                $convertedThresholds[$key] = (int) round($value * 365.25);
            } else {
                $convertedThresholds[$key] = $value;
            }
        }

        $thresholds = array_merge($defaultThresholds, $convertedThresholds);

        if ($ageInDays <= $thresholds['current']) {
            return 'current';
        }

        if ($ageInDays <= $thresholds['medium']) {
            return 'medium';
        }

        return 'old';
    }

    /**
     * Calculate statistics for a package collection.
     *
     * @param array<Package> $packages
     *
     * @return array<string, mixed>
     */
    public function calculateStatistics(array $packages, ?DateTimeImmutable $referenceDate = null): array
    {
        $ages = [];
        $totalReduction = 0;
        $packagesWithDates = 0;
        $packagesWithLatest = 0;

        foreach ($packages as $package) {
            if (null === $package->releaseDate) {
                continue;
            }

            $age = $this->calculateAgeInDays($package->releaseDate, $referenceDate);
            $ages[] = $age;
            ++$packagesWithDates;

            if (null !== $package->latestReleaseDate) {
                $reduction = $this->calculateAgeReduction($package, $referenceDate);
                if (null !== $reduction) {
                    $totalReduction += $reduction;
                    ++$packagesWithLatest;
                }
            }
        }

        if (empty($ages)) {
            return [
                'count' => 0,
                'average_age_days' => null,
                'average_age_formatted' => null,
                'median_age_days' => null,
                'oldest_age_days' => null,
                'newest_age_days' => null,
                'potential_reduction_days' => null,
                'total_reduction_days' => null,
            ];
        }

        sort($ages);
        $count = count($ages);
        $average = array_sum($ages) / $count;
        $median = 0 === $count % 2
            ? ($ages[$count / 2 - 1] + $ages[$count / 2]) / 2
            : $ages[intval($count / 2)];

        return [
            'count' => $count,
            'average_age_days' => $average,
            'average_age_formatted' => $this->formatAge((int) round($average)),
            'median_age_days' => $median,
            'oldest_age_days' => max($ages),
            'newest_age_days' => min($ages),
            'potential_reduction_days' => $packagesWithLatest > 0 ? $totalReduction / $packagesWithLatest : null,
            'total_reduction_days' => $packagesWithLatest > 0 ? $totalReduction : null,
        ];
    }
}
