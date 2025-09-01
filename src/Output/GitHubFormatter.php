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

/**
 * Formats dependency age data as GitHub-compatible Markdown.
 */
final class GitHubFormatter
{
    public function __construct(
        private readonly AgeCalculationService $ageCalculationService,
        private readonly RatingService $ratingService,
    ) {}

    /**
     * Format packages as GitHub-compatible Markdown table.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     */
    public function format(array $packages, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): string
    {
        if (empty($packages)) {
            return "## 📦 Dependency Age Report\n\n_No packages found to analyze._\n";
        }

        $referenceDate ??= new DateTimeImmutable();
        $summary = $this->ratingService->getRatingSummary($packages, $thresholds, $referenceDate);

        $output = $this->formatHeader($summary);
        $output .= $this->formatSummary($summary, $packages, $referenceDate);
        $output .= $this->formatPackageTable($packages, $referenceDate);
        $output .= $this->formatFooter();

        return $output;
    }

    /**
     * Format the report header.
     *
     * @param array<string, mixed> $summary
     */
    private function formatHeader(array $summary): string
    {
        $emoji = $this->getHealthEmoji($summary['health_score'] ?? 100.0);

        return "## {$emoji} Dependency Age Report\n\n";
    }

    /**
     * Format summary statistics.
     *
     * @param array<string, mixed> $summary
     * @param array<Package>       $packages
     */
    private function formatSummary(array $summary, array $packages, DateTimeImmutable $referenceDate): string
    {
        $totalPackages = count($packages);
        $healthScore = $summary['health_score'] ?? 100.0;
        $criticalCount = $summary['critical_count'] ?? 0;

        // Calculate average age
        $totalAge = 0;
        $packagesWithAge = 0;
        foreach ($packages as $package) {
            $age = $package->getAgeInDays($referenceDate);
            if (null !== $age) {
                $totalAge += $age;
                ++$packagesWithAge;
            }
        }
        $averageAge = $packagesWithAge > 0 ? (int) round($totalAge / $packagesWithAge) : 0;

        $output = "### 📊 Summary\n\n";
        $output .= "| Metric | Value |\n";
        $output .= "|--------|-------|\n";
        $output .= "| **Total Packages** | {$totalPackages} |\n";
        $output .= '| **Health Score** | '.sprintf('%.1f%%', $healthScore)." |\n";
        $output .= '| **Average Age** | '.$this->ageCalculationService->formatAge($averageAge)." |\n";

        if ($criticalCount > 0) {
            $output .= "| **Critical Packages** | ⚠️ {$criticalCount} |\n";
        }

        $output .= "\n";

        if ($summary['has_critical'] ?? false) {
            $output .= "> **⚠️ Warning:** Critical packages found! Consider updating these dependencies.\n\n";
        }

        return $output;
    }

    /**
     * Format packages table.
     *
     * @param array<Package> $packages
     */
    private function formatPackageTable(array $packages, DateTimeImmutable $referenceDate): string
    {
        $output = "### 📋 Package Details\n\n";

        $output .= "| Package | Installed | Age | Rating | Latest | Improvement |\n";
        $output .= "|---------|-----------|-----|--------|--------|--------------|\n";

        // Sort packages by age (critical first)
        usort($packages, function (Package $a, Package $b) use ($referenceDate) {
            $ageA = $a->getAgeInDays($referenceDate) ?? 0;
            $ageB = $b->getAgeInDays($referenceDate) ?? 0;

            return $ageB <=> $ageA; // Descending (oldest first)
        });

        foreach ($packages as $package) {
            $output .= $this->formatPackageRow($package, $referenceDate)."\n";
        }

        return $output."\n";
    }

    /**
     * Format a single package row.
     */
    private function formatPackageRow(Package $package, DateTimeImmutable $referenceDate): string
    {
        $age = $package->getAgeInDays($referenceDate);
        $ageFormatted = null !== $age ? $this->ageCalculationService->formatAge($age) : 'Unknown';
        $rating = $this->getRatingEmoji($age);

        $latestInfo = '—';
        $improvement = '—';

        if (null !== $package->latestVersion && null !== $package->latestReleaseDate) {
            $latestInfo = "`{$package->latestVersion}`";

            $latestAge = $referenceDate->diff($package->latestReleaseDate)->days;
            if (false !== $latestAge && null !== $age && $age > $latestAge) {
                $reductionDays = $age - $latestAge;
                $improvement = '📈 '.$this->ageCalculationService->formatAge($reductionDays);
            } elseif ($package->version === $package->latestVersion) {
                $improvement = '✅ Latest';
            }
        }

        return sprintf(
            '| `%s` | `%s` | %s | %s | %s | %s |',
            $this->escapeMarkdown($package->name),
            $this->escapeMarkdown($package->version),
            $ageFormatted,
            $rating,
            $latestInfo,
            $improvement,
        );
    }

    /**
     * Format report footer.
     */
    private function formatFooter(): string
    {
        $date = (new DateTimeImmutable())->format('Y-m-d H:i:s T');

        return "---\n\n";
    }

    /**
     * Get emoji for health score.
     */
    private function getHealthEmoji(float $healthScore): string
    {
        if ($healthScore >= 90) {
            return '🟢';
        } elseif ($healthScore >= 70) {
            return '🟡';
        }

        return '🔴';
    }

    /**
     * Get rating emoji based on package age.
     */
    private function getRatingEmoji(?int $ageInDays): string
    {
        if (null === $ageInDays) {
            return '⚪';
        }

        $ageInYears = $ageInDays / 365.25;

        if ($ageInYears < 0.5) {
            return '🟢 Fresh';
        } elseif ($ageInYears < 1.0) {
            return '🟡 Aging';
        }

        return '🔴 Critical';
    }

    /**
     * Escape special Markdown characters.
     */
    private function escapeMarkdown(string $text): string
    {
        return str_replace(['|', '\n', '\r'], ['\\|', ' ', ' '], $text);
    }
}
