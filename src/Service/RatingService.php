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
use KonradMichalik\ComposerDependencyAge\Exception\ConfigurationException;
use KonradMichalik\ComposerDependencyAge\Exception\ServiceException;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use Throwable;

/**
 * Service for rating packages based on their age and thresholds.
 */
class RatingService
{
    public function __construct(
        private readonly AgeCalculationService $ageCalculationService,
    ) {}

    /**
     * Rate a single package based on its age.
     *
     * @param array<string, mixed> $thresholds
     *
     * @return array<string, mixed>
     *
     * @throws ServiceException
     */
    public function ratePackage(Package $package, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): array
    {
        try {
            if (!empty($thresholds)) {
                $this->validateThresholdsOrThrow($thresholds);
            }

            if (null === $package->releaseDate) {
                return [
                    'category' => 'unknown',
                    'emoji' => '⚪',
                    'description' => 'Unknown',
                    'age_days' => null,
                    'age_formatted' => 'Unknown',
                ];
            }

            $ageDays = $this->ageCalculationService->calculateAgeInDays($package->releaseDate, $referenceDate);
            $category = $this->ageCalculationService->getAgeCategory($ageDays, $thresholds);
            $ageFormatted = $this->ageCalculationService->formatAge($ageDays);

            return [
                'category' => $category,
                'emoji' => $this->getCategoryEmoji($category),
                'description' => $this->getCategoryDescription($category),
                'age_days' => $ageDays,
                'age_formatted' => $ageFormatted,
            ];
        } catch (ConfigurationException $e) {
            // Re-throw configuration exceptions as-is
            throw $e;
        } catch (Throwable $e) {
            throw new ServiceException(sprintf('Failed to rate package "%s": %s', $package->name, $e->getMessage()), previous: $e);
        }
    }

    /**
     * Rate multiple packages.
     *
     * @param array<Package>       $packages
     * @param array<string, mixed> $thresholds
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws ServiceException
     */
    public function ratePackages(mixed $packages, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): array
    {
        if (!is_array($packages)) {
            throw new ServiceException('Packages must be an array');
        }

        $ratings = [];
        foreach ($packages as $index => $package) {
            if (!($package instanceof Package)) {
                throw new ServiceException(sprintf('Invalid package at index %d: expected Package instance, got %s', $index, gettype($package)));
            }

            try {
                $ratings[$package->name] = $this->ratePackage($package, $thresholds, $referenceDate);
            } catch (ServiceException $e) {
                // Re-throw service exceptions to maintain error context
                throw $e;
            }
        }

        return $ratings;
    }

    /**
     * Get distribution of ratings across packages.
     *
     * @param array<Package>       $packages
     * @param array<string, mixed> $thresholds
     *
     * @return array<string, int>
     */
    public function getRatingDistribution(array $packages, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): array
    {
        $distribution = [
            'green' => 0,
            'yellow' => 0,
            'red' => 0,
            'unknown' => 0,
        ];

        $ratings = $this->ratePackages($packages, $thresholds, $referenceDate);

        foreach ($ratings as $rating) {
            ++$distribution[$rating['category']];
        }

        return $distribution;
    }

    /**
     * Get packages filtered by rating category.
     *
     * @param array<Package>       $packages
     * @param array<string, mixed> $thresholds
     *
     * @return array<Package>
     */
    public function getPackagesByRating(array $packages, string $category, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): array
    {
        $filteredPackages = [];
        $ratings = $this->ratePackages($packages, $thresholds, $referenceDate);

        foreach ($packages as $package) {
            if ($ratings[$package->name]['category'] === $category) {
                $filteredPackages[] = $package;
            }
        }

        return $filteredPackages;
    }

    /**
     * Check if any packages exceed critical thresholds.
     *
     * @param array<Package>       $packages
     * @param array<string, mixed> $thresholds
     */
    public function hasCriticalPackages(array $packages, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): bool
    {
        $redPackages = $this->getPackagesByRating($packages, 'red', $thresholds, $referenceDate);

        return !empty($redPackages);
    }

    /**
     * Get summary statistics for rating distribution.
     *
     * @param array<Package>       $packages
     * @param array<string, mixed> $thresholds
     *
     * @return array<string, mixed>
     */
    public function getRatingSummary(array $packages, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): array
    {
        $distribution = $this->getRatingDistribution($packages, $thresholds, $referenceDate);
        $total = array_sum($distribution);

        if (0 === $total) {
            return [
                'total_packages' => 0,
                'distribution' => $distribution,
                'percentages' => [
                    'green' => 0.0,
                    'yellow' => 0.0,
                    'red' => 0.0,
                    'unknown' => 0.0,
                ],
                'has_critical' => false,
                'health_score' => 0.0,
            ];
        }

        $percentages = [];
        foreach ($distribution as $category => $count) {
            $percentages[$category] = round(($count / $total) * 100, 1);
        }

        // Calculate health score (100% for all green, 0% for all red)
        $healthScore = (($distribution['green'] * 1.0) + ($distribution['yellow'] * 0.5)) / $total * 100;

        return [
            'total_packages' => $total,
            'distribution' => $distribution,
            'percentages' => $percentages,
            'has_critical' => $distribution['red'] > 0,
            'health_score' => round($healthScore, 1),
        ];
    }

    /**
     * Get the emoji representation for a rating category.
     */
    public function getCategoryEmoji(string $category): string
    {
        return match ($category) {
            'green' => '🟢',
            'yellow' => '🟡',
            'red' => '🔴',
            'unknown' => '⚪',
            default => '❓',
        };
    }

    /**
     * Get the description for a rating category.
     */
    public function getCategoryDescription(string $category): string
    {
        return match ($category) {
            'green' => 'Current',
            'yellow' => 'Outdated',
            'red' => 'Critical',
            'unknown' => 'Unknown',
            default => 'Unknown',
        };
    }

    /**
     * Get detailed rating with additional context.
     *
     * @param array<string, mixed> $thresholds
     *
     * @return array<string, mixed>
     */
    public function getDetailedRating(Package $package, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): array
    {
        $basicRating = $this->ratePackage($package, $thresholds, $referenceDate);

        $detailed = [
            'package' => $package->name,
            'version' => $package->version,
            'is_dev' => $package->isDev,
            'rating' => $basicRating,
            'latest_info' => null,
            'age_reduction' => null,
        ];

        // Add latest version information if available
        if (null !== $package->latestVersion && null !== $package->latestReleaseDate) {
            $latestAgedays = $this->ageCalculationService->calculateAgeInDays($package->latestReleaseDate, $referenceDate);
            $latestCategory = $this->ageCalculationService->getAgeCategory($latestAgedays, $thresholds);

            $detailed['latest_info'] = [
                'version' => $package->latestVersion,
                'age_days' => $latestAgedays,
                'age_formatted' => $this->ageCalculationService->formatAge($latestAgedays),
                'category' => $latestCategory,
                'emoji' => $this->getCategoryEmoji($latestCategory),
            ];

            // Calculate potential age reduction
            $reduction = $this->ageCalculationService->calculateAgeReduction($package, $referenceDate);
            if (null !== $reduction) {
                $detailed['age_reduction'] = [
                    'days' => $reduction,
                    'formatted' => $this->ageCalculationService->formatAge($reduction),
                ];
            }
        }

        return $detailed;
    }

    /**
     * Validate threshold configuration.
     *
     * @param array<string, mixed> $thresholds
     *
     * @return array<string>
     */
    public function validateThresholds(array $thresholds): array
    {
        $errors = [];

        // Check required keys
        $requiredKeys = ['green', 'yellow'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $thresholds)) {
                $errors[] = "Missing required threshold key: {$key}";
                continue;
            }

            // Check if value is numeric and positive
            if (!is_numeric($thresholds[$key]) || $thresholds[$key] < 0) {
                $errors[] = "Threshold '{$key}' must be a positive number, got: ".gettype($thresholds[$key]);
            }
        }

        // If we have both keys, check logical order
        if (empty($errors) || count($errors) < 2) {
            if (isset($thresholds['green'], $thresholds['yellow']) && $thresholds['green'] >= $thresholds['yellow']) {
                $errors[] = "Green threshold ({$thresholds['green']}) must be less than yellow threshold ({$thresholds['yellow']})";
            }
        }

        return $errors;
    }

    /**
     * Validate thresholds and throw exception on error.
     *
     * @param array<string, mixed> $thresholds
     *
     * @throws ConfigurationException
     */
    private function validateThresholdsOrThrow(array $thresholds): void
    {
        $errors = $this->validateThresholds($thresholds);
        if (!empty($errors)) {
            throw new ConfigurationException('Invalid threshold configuration: '.implode(', ', $errors));
        }
    }

    /**
     * Convert threshold values from years to days.
     *
     * @param array<string, mixed> $thresholds
     *
     * @return array<string, int>
     */
    public function convertThresholdsToDays(array $thresholds): array
    {
        $converted = [];
        foreach ($thresholds as $key => $value) {
            $converted[$key] = (int) round($value * 365.25); // Account for leap years
        }

        return $converted;
    }
}
