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

use ConsoleStyleKit\ConsoleStyleKit;
use ConsoleStyleKit\Elements\RatingElement;
use DateTimeImmutable;
use KonradMichalik\ComposerDependencyAge\Model\Package;

/**
 * ReleaseCycleService.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
class ReleaseCycleService
{
    public function __construct(
        private readonly AgeCalculationService $ageService,
    ) {}

    /**
     * Analyze release cycle patterns for a package.
     *
     * @return array<string, mixed>
     */
    public function analyzeReleaseCycle(Package $package, ?DateTimeImmutable $referenceDate = null): array
    {
        if (empty($package->releaseHistory)) {
            return [
                'type' => 'unknown',
                'rating' => 0,
                'frequency_days' => null,
                'trend' => 'unknown',
                'last_release_age' => null,
                'pattern_description' => 'Insufficient release history data',
            ];
        }

        $intervals = $this->calculateReleaseIntervals($package->releaseHistory);
        if (empty($intervals)) {
            return [
                'type' => 'single_release',
                'rating' => 1,
                'frequency_days' => null,
                'trend' => 'unknown',
                'last_release_age' => $this->calculateLastReleaseAge(
                    isset($package->releaseHistory[0]['date']) && $package->releaseHistory[0]['date'] instanceof DateTimeImmutable
                        ? $package->releaseHistory[0]['date']
                        : null,
                    $referenceDate,
                ),
                'pattern_description' => 'Only single release found',
            ];
        }

        $avgFrequency = array_sum($intervals) / count($intervals);

        return [
            'type' => $this->categorizeReleasePattern($avgFrequency),
            'rating' => $this->calculateCycleRating($avgFrequency),
            'frequency_days' => (int) round($avgFrequency),
            'trend' => $this->detectReleaseTrend($intervals),
            'last_release_age' => $this->calculateLastReleaseAge(
                isset($package->releaseHistory[0]['date']) && $package->releaseHistory[0]['date'] instanceof DateTimeImmutable
                    ? $package->releaseHistory[0]['date']
                    : null,
                $referenceDate,
            ),
            'pattern_description' => $this->getPatternDescription($avgFrequency, $intervals),
        ];
    }

    /**
     * Format cycle rating using RatingElement.
     *
     * @param array<string, mixed> $cycleAnalysis
     */
    public function formatCycleRating(array $cycleAnalysis, ?ConsoleStyleKit $style = null): string
    {
        if (null === $style) {
            // Fallback for Events/non-style contexts
            return match ($cycleAnalysis['rating']) {
                3 => '●●●',
                2 => '●●○',
                1 => '●○○',
                default => '○○○',
            };
        }

        return RatingElement::circle($style, 3, $cycleAnalysis['rating'], true)->__toString();
    }

    /**
     * Calculate intervals between releases in days.
     *
     * @param array<array<string, mixed>> $releaseHistory
     *
     * @return array<int>
     */
    private function calculateReleaseIntervals(array $releaseHistory): array
    {
        if (count($releaseHistory) < 2) {
            return [];
        }

        $intervals = [];
        for ($i = 0; $i < count($releaseHistory) - 1; ++$i) {
            $current = $releaseHistory[$i]['date'];
            $next = $releaseHistory[$i + 1]['date'];

            if ($current instanceof DateTimeImmutable && $next instanceof DateTimeImmutable) {
                $interval = $current->diff($next)->days;
                if (is_int($interval) && $interval >= 0) {
                    $intervals[] = $interval;
                }
            }
        }

        return $intervals;
    }

    /**
     * Categorize release pattern based on average frequency.
     */
    private function categorizeReleasePattern(float $avgFrequency): string
    {
        return match (true) {
            $avgFrequency <= 60 => 'very_active',    // ≤ 2 months
            $avgFrequency <= 180 => 'active',        // ≤ 6 months
            $avgFrequency <= 365 => 'moderate',      // ≤ 12 months
            $avgFrequency <= 730 => 'slow',          // ≤ 24 months
            default => 'inactive',                    // > 24 months
        };
    }

    /**
     * Calculate cycle rating (0-3 scale).
     */
    private function calculateCycleRating(float $avgFrequency): int
    {
        return match (true) {
            $avgFrequency <= 60 => 3,    // Very active: ≤ 2 months
            $avgFrequency <= 180 => 2,   // Active: ≤ 6 months
            $avgFrequency <= 365 => 1,   // Moderate: ≤ 12 months
            default => 0,                 // Inactive: > 12 months
        };
    }

    /**
     * Detect trend in release frequency.
     *
     * @param array<int> $intervals
     */
    private function detectReleaseTrend(array $intervals): string
    {
        if (count($intervals) < 3) {
            return 'unknown';
        }

        $halfPoint = (int) floor(count($intervals) / 2);
        $recent = array_slice($intervals, 0, $halfPoint);
        $older = array_slice($intervals, $halfPoint);

        if (empty($recent) || empty($older)) {
            return 'stable';
        }

        $recentAvg = array_sum($recent) / count($recent);
        $olderAvg = array_sum($older) / count($older);

        if ($recentAvg < $olderAvg * 0.7) {
            return 'accelerating';  // Releases getting more frequent
        }
        if ($recentAvg > $olderAvg * 1.5) {
            return 'slowing';      // Releases getting less frequent
        }

        return 'stable';
    }

    /**
     * Calculate age of last release.
     */
    private function calculateLastReleaseAge(?DateTimeImmutable $lastReleaseDate, ?DateTimeImmutable $referenceDate = null): ?int
    {
        if (null === $lastReleaseDate) {
            return null;
        }

        return $this->ageService->calculateAgeInDays($lastReleaseDate, $referenceDate);
    }

    /**
     * Get human-readable pattern description.
     *
     * @param array<int> $intervals
     */
    private function getPatternDescription(float $avgFrequency, array $intervals): string
    {
        $type = $this->categorizeReleasePattern($avgFrequency);
        $trend = $this->detectReleaseTrend($intervals);

        $baseDescription = match ($type) {
            'very_active' => 'Very active development',
            'active' => 'Active development',
            'moderate' => 'Moderate development pace',
            'slow' => 'Slow development pace',
            'inactive' => 'Inactive or abandoned',
            default => 'Unknown pattern',
        };

        $trendDescription = match ($trend) {
            'accelerating' => ', accelerating recently',
            'slowing' => ', slowing down',
            'stable' => ', consistent pace',
            default => '',
        };

        return $baseDescription.$trendDescription;
    }
}
