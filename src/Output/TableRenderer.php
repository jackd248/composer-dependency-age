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
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 *
 * @package ComposerDependencyAge
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

        // Sort packages by age (oldest first)
        $sortedPackages = $this->sortPackagesByAge($packages, $referenceDate);

        $table = new Table($output);
        $table->setHeaders($this->getColumnHeaders($columns));

        // Add data rows
        foreach ($sortedPackages as $package) {
            $rating = $this->ratingService->ratePackage($package, $thresholds, $referenceDate);
            $detailed = $this->ratingService->getDetailedRating($package, $thresholds, $referenceDate);

            $row = $this->formatTableRow($package, $rating, $detailed, $columns);
            $table->addRow($row);
        }

        // Add separator
        $table->addRow(new \Symfony\Component\Console\Helper\TableSeparator());

        // Add summary row
        $summaryRow = $this->formatSummaryRow($sortedPackages, $columns, $thresholds, $referenceDate);
        $table->addRow($summaryRow);

        $table->render();

        // Show legend after table
        $this->renderLegend($output);

        // Show summary
        $directModeActive = $options['direct_mode_active'] ?? false;
        $this->renderSummary($packages, $output, $thresholds, $referenceDate, $directModeActive);
    }

    /**
     * Get default columns for table display.
     *
     * @return array<string>
     */
    public function getDefaultColumns(): array
    {
        return ['package', 'version', 'type', 'age', 'rating', 'latest', 'impact'];
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
            'type' => 'Type',
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
                'type' => $this->formatDependencyType($package),
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
     * Format summary row for the table.
     *
     * @param array<Package>       $packages
     * @param array<string>        $columns
     * @param array<string, float> $thresholds
     *
     * @return array<string>
     */
    private function formatSummaryRow(array $packages, array $columns, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): array
    {
        $row = [];
        $totalAge = $this->formatTotalPackageAge($packages, $referenceDate);
        $overallRating = $this->getOverallRating($packages, $thresholds, $referenceDate);

        foreach ($columns as $column) {
            $row[] = match ($column) {
                'package' => '∑', // Sum symbol
                'version' => '',
                'type' => '',
                'age' => '<options=bold>'.$totalAge.'</options=bold>',
                'rating' => $overallRating,
                'latest' => '',
                'impact' => '',
                'notes' => '',
                'dev' => '',
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
            return '<fg=gray>?</fg=gray>';
        }

        return match ($rating['category']) {
            'current' => '<fg=green>✓</fg=green>',
            'medium' => '<fg=yellow>~</fg=yellow>',
            'old' => '<fg=red>!</fg=red>',
            default => '<fg=gray>?</fg=gray>',
        };
    }

    /**
     * Format dependency type with colors.
     */
    private function formatDependencyType(Package $package): string
    {
        if ($package->isDev && $package->isDirect) {
            return '<fg=magenta>*</fg=magenta>';
        } elseif ($package->isDev && !$package->isDirect) {
            return '<fg=magenta>*</fg=magenta><fg=white>~</fg=white>';
        } elseif ($package->isDirect) {
            return '<fg=cyan>→</fg=cyan>';
        } else {
            return '<fg=white>~</fg=white>';
        }
    }

    /**
     * Normalize version string by removing common prefixes.
     */
    private function normalizeVersion(string $version): string
    {
        return ltrim($version, 'v');
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

        // Don't show if the "latest" version appears older than installed version
        if (version_compare($this->normalizeVersion($latestVersion), $this->normalizeVersion($package->version), '<')) {
            return '';
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

        // Don't show impact if the "latest" version appears older than installed version
        if (version_compare($this->normalizeVersion($latestVersion), $this->normalizeVersion($package->version), '<')) {
            return '';
        }

        if (null === $detailed['age_reduction']) {
            return '';
        }

        $reduction = $detailed['age_reduction'];

        // Don't show impact if reduction is 0 or negative
        if (isset($reduction['days']) && $reduction['days'] <= 0) {
            return '';
        }

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
        if (null !== $latestVersion
            && $latestVersion !== $package->version
            && version_compare($this->normalizeVersion($latestVersion), $this->normalizeVersion($package->version), '>')) {
            $notes[] = 'Update available';
        }

        return empty($notes) ? '' : implode(', ', $notes);
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

        // Format based on scale for better readability
        if ($totalAgeDays < 30) {
            return sprintf('%d days', $totalAgeDays);
        } elseif ($totalAgeDays < 365) {
            $months = $totalAgeDays / 30.44;

            return sprintf('%.1f months', $months);
        } else {
            $years = $totalAgeDays / 365.25;

            return sprintf('%.1f years', $years);
        }
    }

    /**
     * Format total update impact.
     *
     * @param array<string, mixed> $statistics
     */
    private function formatTotalUpdateImpact(array $statistics): string
    {
        if (null === $statistics['total_reduction_days']) {
            return '';
        }

        $totalReductionDays = $statistics['total_reduction_days'];

        // Format based on scale for better readability
        if ($totalReductionDays < 30) {
            return sprintf('- %d days', $totalReductionDays);
        } elseif ($totalReductionDays < 365) {
            $months = $totalReductionDays / 30.44;

            return sprintf('- %.1f months', $months);
        } else {
            $years = $totalReductionDays / 365.25;

            return sprintf('- %.1f years', $years);
        }
    }

    /**
     * Sort packages by age (oldest first).
     *
     * @param array<Package> $packages
     *
     * @return array<Package>
     */
    private function sortPackagesByAge(array $packages, ?DateTimeImmutable $referenceDate): array
    {
        $sortedPackages = $packages;

        usort($sortedPackages, function (Package $a, Package $b) use ($referenceDate): int {
            $ageA = $a->getAgeInDays($referenceDate);
            $ageB = $b->getAgeInDays($referenceDate);

            // Packages without age (null) go to the end
            if (null === $ageA && null === $ageB) {
                return 0;
            }
            if (null === $ageA) {
                return 1;
            }
            if (null === $ageB) {
                return -1;
            }

            // Sort by age descending (oldest first)
            return $ageB <=> $ageA;
        });

        return $sortedPackages;
    }

    /**
     * Render legend for symbols used in the table.
     */
    private function renderLegend(OutputInterface $output): void
    {
        $output->writeln('Legend:');
        $output->writeln('- Rating: <fg=green>✓</fg=green> mostly current, <fg=yellow>~</fg=yellow> moderately current, <fg=red>!</fg=red> chronologically old, <fg=gray>?</fg=gray> unknown');
        $output->writeln('- Type: → direct dependency, ~ indirect dependency, * dev dependency');
        $output->writeln('');
    }

    /**
     * Render summary statistics.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     */
    private function renderSummary(array $packages, OutputInterface $output, array $thresholds = [], ?DateTimeImmutable $referenceDate = null, bool $directModeActive = false): void
    {
        if (empty($packages)) {
            return;
        }

        $statistics = $this->ageCalculationService->calculateStatistics($packages, $referenceDate);

        $output->writeln('<info>Summary (*)</info>');
        $output->writeln('<info>---------------------</info>');
        $output->writeln('');

        $summaryTable = new Table($output);
        $summaryTable->setHeaders(['Metric', 'Value']);

        $totalAge = $this->formatTotalPackageAge($packages, $referenceDate);
        $averageAge = $statistics['average_age_formatted'] ?? 'Unknown';
        $packageCounts = $this->getPackageCounts($packages, $thresholds, $referenceDate);
        $updateImpact = $this->formatTotalUpdateImpact($statistics);
        $overallRating = $this->getOverallRating($packages, $thresholds, $referenceDate);

        $summaryTable->addRow(['Total Age', '<options=bold>'.$totalAge.'</options=bold>']);
        $summaryTable->addRow(['Average Age', $averageAge]);
        $summaryTable->addRow(['Packages', $packageCounts]);

        // Only show Update Impact if there's actual data
        if (!empty($updateImpact)) {
            $summaryTable->addRow(['Update Impact', $updateImpact]);
        }

        $summaryTable->addRow(['Rating', $overallRating]);

        $summaryTable->render();

        // Add verbose explanation of rating calculation
        if ($output->isVerbose()) {
            $this->renderVerboseRatingExplanation($packages, $output, $thresholds, $referenceDate);
        }

        if (!$directModeActive) {
            $output->writeln('');
            $output->writeln('* By default, all composer dependencies are shown. For a focused analysis on direct dependencies only, use the <fg=cyan>`--direct`</fg=cyan> option.');
            $output->writeln('Focusing on direct dependencies gives a clearer picture of your project\'s core dependency health.*');
        }
    }

    /**
     * Get package counts by rating category.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     */
    private function getPackageCounts(array $packages, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): string
    {
        $counts = ['current' => 0, 'medium' => 0, 'old' => 0, 'unknown' => 0];

        foreach ($packages as $package) {
            $rating = $this->ratingService->ratePackage($package, $thresholds, $referenceDate);
            $category = $rating['category'] ?? 'unknown';
            ++$counts[$category];
        }

        $total = count($packages);
        $current = $counts['current'];
        $medium = $counts['medium'];
        $old = $counts['old'];

        return sprintf('%d Packages (%d <fg=green>✓</fg=green>, %d <fg=yellow>~</fg=yellow>, %d <fg=red>!</fg=red>)', $total, $current, $medium, $old);
    }

    /**
     * Get overall project rating using RatingService.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     */
    private function getOverallRating(array $packages, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): string
    {
        if (empty($packages)) {
            return '<fg=gray>?</fg=gray> unknown';
        }

        $summary = $this->ratingService->getRatingSummary($packages, $thresholds, $referenceDate);

        return $summary['overall_rating'] ?? '<fg=gray>?</fg=gray> unknown';
    }

    /**
     * Render verbose explanation of how the overall rating is calculated.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     */
    private function renderVerboseRatingExplanation(array $packages, OutputInterface $output, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): void
    {
        $output->writeln('');
        $output->writeln('<comment>Rating Calculation Details:</comment>');

        // Calculate distribution
        $summary = $this->ratingService->getRatingSummary($packages, $thresholds, $referenceDate);
        $distribution = $summary['distribution'] ?? [];
        $percentages = $summary['percentages'] ?? [];
        $total = $summary['total_packages'] ?? count($packages);

        if (0 === $total) {
            $output->writeln('No packages to analyze.');

            return;
        }

        $currentPercent = round($percentages['current'] ?? 0, 1);
        $mediumPercent = round($percentages['medium'] ?? 0, 1);
        $oldPercent = round($percentages['old'] ?? 0, 1);
        $unknownPercent = round($percentages['unknown'] ?? 0, 1);

        $output->writeln(sprintf('Package Distribution:'));
        $output->writeln(sprintf('  • <fg=green>Current</fg=green> (≤ 6 months): %d packages (%.1f%%)', $distribution['current'] ?? 0, $currentPercent));
        $output->writeln(sprintf('  • <fg=yellow>Medium</fg=yellow> (≤ 12 months): %d packages (%.1f%%)', $distribution['medium'] ?? 0, $mediumPercent));
        $output->writeln(sprintf('  • <fg=red>Old</fg=red> (> 12 months): %d packages (%.1f%%)', $distribution['old'] ?? 0, $oldPercent));
        if (($distribution['unknown'] ?? 0) > 0) {
            $output->writeln(sprintf('  • <fg=gray>Unknown</fg=gray>: %d packages (%.1f%%)', $distribution['unknown'] ?? 0, $unknownPercent));
        }

        $output->writeln('');
        $output->writeln('Overall Rating Logic:');

        if ($currentPercent >= 70) {
            $output->writeln(sprintf('  → <fg=green>✓ Mostly Current</fg=green>: %.1f%% current packages ≥ 70%%', $currentPercent));
        } elseif ($oldPercent >= 30) {
            $output->writeln(sprintf('  → <fg=red>! Needs Attention</fg=red>: %.1f%% old packages ≥ 30%%', $oldPercent));
        } else {
            $output->writeln(sprintf('  → <fg=yellow>~ Moderately Current</fg=yellow>: %.1f%% current < 70%% AND %.1f%% old < 30%%', $currentPercent, $oldPercent));
        }
    }
}
