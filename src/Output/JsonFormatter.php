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

namespace KonradMichalik\ComposerDependencyAge\Output;

use DateTimeImmutable;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\RatingService;
use RuntimeException;

/**
 * Formats dependency age data as JSON for CI/CD integration.
 */
final class JsonFormatter
{
    public function __construct(
        private readonly AgeCalculationService $ageCalculationService,
        private readonly RatingService $ratingService,
    ) {}

    /**
     * Format packages and summary data as JSON.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     */
    public function format(array $packages, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): string
    {
        $formattedData = $this->formatAsArray($packages, $thresholds, $referenceDate);

        $jsonOutput = json_encode($formattedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $jsonOutput) {
            throw new RuntimeException('Failed to encode dependency age data as JSON');
        }

        return $jsonOutput;
    }

    /**
     * Format packages and summary data as array (for reuse).
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     *
     * @return array<string, mixed>
     */
    public function formatAsArray(array $packages, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): array
    {
        $referenceDate ??= new DateTimeImmutable();

        // Get ratings for all packages
        $ratings = $this->ratingService->ratePackages($packages, $thresholds, $referenceDate);
        $summary = $this->ratingService->getRatingSummary($packages, $thresholds, $referenceDate);

        // Format individual packages
        $formattedPackages = [];
        foreach ($packages as $package) {
            $formattedPackages[] = $this->formatPackage($package, $referenceDate);
        }

        return [
            'summary' => $this->formatSummary($summary, $packages, $referenceDate),
            'packages' => $formattedPackages,
        ];
    }

    /**
     * Format a single package for JSON output.
     *
     * @return array<string, mixed>
     */
    private function formatPackage(Package $package, DateTimeImmutable $referenceDate): array
    {
        $ageInDays = $package->getAgeInDays($referenceDate);
        $latestAgeInDays = null;
        $ageReductionDays = null;

        if (null !== $package->latestReleaseDate) {
            $latestDiff = $referenceDate->diff($package->latestReleaseDate);
            $latestAgeInDays = false === $latestDiff->days ? null : $latestDiff->days;

            if (null !== $ageInDays && null !== $latestAgeInDays) {
                $ageReductionDays = $ageInDays - $latestAgeInDays;
            }
        }

        return [
            'name' => $package->name,
            'installed_version' => $package->version,
            'installed_release_date' => $package->releaseDate?->format('c'),
            'age_days' => $ageInDays,
            'age_formatted' => null !== $ageInDays ? $this->ageCalculationService->formatAge($ageInDays) : null,
            'rating' => $this->determineRating($ageInDays),
            'latest_version' => $package->latestVersion,
            'latest_release_date' => $package->latestReleaseDate?->format('c'),
            'latest_age_days' => $latestAgeInDays,
            'latest_age_formatted' => null !== $latestAgeInDays ? $this->ageCalculationService->formatAge($latestAgeInDays) : null,
            'age_reduction_days' => $ageReductionDays,
            'age_reduction_formatted' => null !== $ageReductionDays && $ageReductionDays > 0
                ? $this->ageCalculationService->formatAge($ageReductionDays)
                : null,
        ];
    }

    /**
     * Format summary data for JSON output.
     *
     * @param array<string, mixed> $summary
     * @param array<Package>       $packages
     *
     * @return array<string, mixed>
     */
    private function formatSummary(array $summary, array $packages, DateTimeImmutable $referenceDate): array
    {
        $totalAgeInDays = 0;
        $potentialReductionDays = 0;
        $packagesWithAge = 0;
        $packagesWithReduction = 0;

        foreach ($packages as $package) {
            $ageInDays = $package->getAgeInDays($referenceDate);
            if (null !== $ageInDays) {
                $totalAgeInDays += $ageInDays;
                ++$packagesWithAge;

                if (null !== $package->latestReleaseDate) {
                    $latestDiff = $referenceDate->diff($package->latestReleaseDate);
                    $latestAgeInDays = false === $latestDiff->days ? null : $latestDiff->days;

                    if (null !== $latestAgeInDays && $ageInDays > $latestAgeInDays) {
                        $potentialReductionDays += ($ageInDays - $latestAgeInDays);
                        ++$packagesWithReduction;
                    }
                }
            }
        }

        $averageAgeDays = $packagesWithAge > 0 ? (int) round($totalAgeInDays / $packagesWithAge) : 0;
        $averagePotentialReductionDays = $packagesWithReduction > 0 ? (int) round($potentialReductionDays / $packagesWithReduction) : 0;

        return [
            'total_packages' => count($packages),
            'average_age_days' => $averageAgeDays,
            'average_age_formatted' => $averageAgeDays > 0 ? $this->ageCalculationService->formatAge($averageAgeDays) : '0 days',
            'critical_count' => $summary['critical_count'] ?? 0,
            'potential_age_reduction_days' => $averagePotentialReductionDays,
            'potential_age_reduction_formatted' => $averagePotentialReductionDays > 0
                ? $this->ageCalculationService->formatAge($averagePotentialReductionDays)
                : '0 days',
            'health_score' => $summary['health_score'] ?? 100.0,
            'has_critical' => $summary['has_critical'] ?? false,
        ];
    }

    /**
     * Determine package rating based on age in days.
     */
    private function determineRating(?int $ageInDays): string
    {
        if (null === $ageInDays) {
            return 'unknown';
        }

        // Convert days to years for rating
        $ageInYears = $ageInDays / 365.25;

        if ($ageInYears < 0.5) {
            return 'green';
        } elseif ($ageInYears < 1.0) {
            return 'yellow';
        }

        return 'red';
    }
}
