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
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Service for rendering package data as CLI tables using Symfony Console Table.
 */
class TableRenderer
{
    public function __construct(
        private readonly AgeCalculationService $ageCalculationService,
        private readonly RatingService $ratingService,
    ) {}

    /**
     * Render packages as a formatted CLI table using Symfony Console Table.
     *
     * @param array<Package>       $packages
     * @param array<string, mixed> $options
     * @param array<string, float> $thresholds
     */
    public function renderTable(
        array $packages,
        OutputInterface $output,
        array $options = [],
        array $thresholds = [],
        ?DateTimeImmutable $referenceDate = null,
    ): void {
        if (empty($packages)) {
            $output->writeln('No packages found.');

            return;
        }

        $columns = $options['columns'] ?? $this->getDefaultColumns();
        $thresholds = $thresholds ?: ($options['thresholds'] ?? []);
        $referenceDate = $referenceDate ?: ($options['reference_date'] ?? null);

        $table = new Table($output);
        $table->setHeaders($this->getColumnHeaders($columns));

        // Add data rows
        foreach ($packages as $package) {
            $rating = $this->ratingService->ratePackage($package, $thresholds, $referenceDate);
            $detailed = $this->ratingService->getDetailedRating($package, $thresholds, $referenceDate);

            $row = $this->formatTableRow($package, $rating, $detailed, $columns);
            $table->addRow($row);
        }

        // Add summary row
        $summaryRow = $this->formatSummaryRow($packages, $columns, $referenceDate);
        if (!empty($summaryRow)) {
            $table->addRow(array_fill(0, count($columns), '-'));  // Separator row
            $table->addRow($summaryRow);
        }

        $table->render();
    }

    /**
     * Get default columns for table display.
     *
     * @return array<string>
     */
    public function getDefaultColumns(): array
    {
        return ['package', 'version', 'age', 'rating', 'latest', 'impact', 'notes'];
    }

    /**
     * Get column headers.
     *
     * @param array<string> $columns
     *
     * @return array<string>
     */
    private function getColumnHeaders(array $columns): array
    {
        $headerMap = [
            'package' => 'Package Name',
            'version' => 'Installed Version',
            'age' => 'Age',
            'rating' => 'Rating',
            'latest' => 'Latest Version',
            'impact' => 'Update Impact',
            'notes' => 'Notes',
            'dev' => 'Dev Dependency',
        ];

        return array_map(fn ($col) => $headerMap[$col] ?? ucfirst($col), $columns);
    }

    /**
     * Format a single table row.
     *
     * @param array<string, mixed> $rating
     * @param array<string, mixed> $detailed
     * @param array<string>        $columns
     *
     * @return array<string>
     */
    private function formatTableRow(Package $package, array $rating, array $detailed, array $columns): array
    {
        $row = [];

        foreach ($columns as $column) {
            $row[] = match ($column) {
                'package' => $package->name,
                'version' => $package->version,
                'age' => $rating['age_formatted'] ?? 'Unknown',
                'rating' => $this->formatRating($rating),
                'latest' => $this->formatLatestVersion($package, $detailed),
                'impact' => $this->formatImpact($package, $detailed),
                'notes' => $this->formatNotes($package, $detailed),
                'dev' => $package->isDev ? 'Yes' : 'No',
                default => '',
            };
        }

        return $row;
    }

    /**
     * Format rating with emoji and color.
     *
     * @param array<string, mixed> $rating
     */
    private function formatRating(array $rating): string
    {
        if ('unknown' === $rating['category']) {
            return '⚪ Unknown';
        }

        $emoji = $rating['emoji'] ?? '';
        $description = match ($rating['category']) {
            'green' => 'Current',
            'yellow' => 'Outdated',
            'red' => 'Critical',
            default => 'Unknown',
        };

        return $emoji.' '.$description;
    }

    /**
     * Format latest version - only show if different from installed.
     *
     * @param array<string, mixed> $detailed
     */
    private function formatLatestVersion(Package $package, array $detailed): string
    {
        $latestVersion = $detailed['latest_info']['version'] ?? null;

        if (null === $latestVersion || $latestVersion === $package->version) {
            return '';  // Don't show if same as installed or not available
        }

        return $latestVersion;
    }

    /**
     * Format update impact.
     *
     * @param array<string, mixed> $detailed
     */
    private function formatImpact(Package $package, array $detailed): string
    {
        $latestVersion = $detailed['latest_info']['version'] ?? null;

        // No impact if no update available or same version
        if (null === $latestVersion || $latestVersion === $package->version) {
            return '';
        }

        if (null === $detailed['age_reduction']) {
            return '';
        }

        $reduction = $detailed['age_reduction'];

        return '-'.$reduction['formatted'];
    }

    /**
     * Format notes column.
     *
     * @param array<string, mixed> $detailed
     */
    private function formatNotes(Package $package, array $detailed): string
    {
        $notes = [];

        if ($package->isDev) {
            $notes[] = 'Dev';
        }

        // Only show "Update available" if there's actually a newer version
        $latestVersion = $detailed['latest_info']['version'] ?? null;
        if (null !== $latestVersion && $latestVersion !== $package->version) {
            $notes[] = 'Update available';
        }

        if ('red' === $detailed['rating']['category']) {
            $notes[] = 'Critical';
        }

        return empty($notes) ? '' : implode(', ', $notes);
    }

    /**
     * Format summary row as specified in requirements.
     *
     * @param array<Package> $packages
     * @param array<string>  $columns
     *
     * @return array<string>
     */
    private function formatSummaryRow(array $packages, array $columns, ?DateTimeImmutable $referenceDate): array
    {
        if (empty($packages)) {
            return [];
        }

        $statistics = $this->ageCalculationService->calculateStatistics($packages, $referenceDate);

        $row = [];
        foreach ($columns as $column) {
            $row[] = match ($column) {
                'package' => '',  // Empty for first column
                'version' => 'Total Age',
                'age' => $this->formatTotalPackageAge($packages, $referenceDate),
                'rating' => 'Average Age',
                'latest' => $statistics['average_age_formatted'] ?? 'Unknown',
                'impact' => 'Total Update Impact',
                'notes' => $this->formatTotalUpdateImpact($statistics),
                default => '',
            };
        }

        return $row;
    }

    /**
     * Calculate and format total age of all packages.
     *
     * @param array<Package> $packages
     */
    private function formatTotalPackageAge(array $packages, ?DateTimeImmutable $referenceDate): string
    {
        $totalAgeDays = 0;
        foreach ($packages as $package) {
            $age = $package->getAgeInDays($referenceDate);
            if (null !== $age) {
                $totalAgeDays += $age;
            }
        }

        $totalAgeYears = $totalAgeDays / 365.25;

        return sprintf('%.1f years', $totalAgeYears);
    }

    /**
     * Format total update impact.
     *
     * @param array<string, mixed> $statistics
     */
    private function formatTotalUpdateImpact(array $statistics): string
    {
        if (null === $statistics['potential_reduction_days']) {
            return '';
        }

        $reductionMonths = $statistics['potential_reduction_days'] / 30.44;

        return sprintf('- %.1f months', $reductionMonths);
    }
}
