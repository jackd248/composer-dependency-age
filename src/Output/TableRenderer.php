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

use ConsoleStyleKit\ConsoleStyleKit;
use ConsoleStyleKit\Elements\RatingElement;
use ConsoleStyleKit\Enums\BlockquoteType;
use DateTimeImmutable;
use KonradMichalik\ComposerDependencyAge\Configuration\Configuration;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\RatingService;
use KonradMichalik\ComposerDependencyAge\Service\ReleaseCycleService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * TableRenderer.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
class TableRenderer
{
    public function __construct(
        private readonly AgeCalculationService $ageCalculationService,
        private readonly RatingService $ratingService,
        private readonly ?ReleaseCycleService $releaseCycleService = null,
        private readonly ?Configuration $configuration = null,
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
        InputInterface $input,
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

            $row = $this->formatTableRow($package, $rating, $detailed, $columns, new ConsoleStyleKit($input, $output));
            $table->addRow($row);
        }

        // Add separator
        $table->addRow(new \Symfony\Component\Console\Helper\TableSeparator());

        // Add summary row
        $summaryRow = $this->formatSummaryRow($sortedPackages, $columns, $thresholds, $referenceDate, new ConsoleStyleKit($input, $output));
        $table->addRow($summaryRow);

        $table->render();

        // Show legend after table
        $this->renderLegend($input, $output);

        // Show summary
        $directModeActive = $options['direct_mode_active'] ?? false;
        $this->renderSummary($packages, $output, $input, $thresholds, $referenceDate, $directModeActive);
    }

    /**
     * Get default columns for table display.
     *
     * @return array<string>
     */
    public function getDefaultColumns(): array
    {
        $columns = ['package', 'version', 'age', 'rating'];

        // Add cycle column if release cycle analysis is enabled
        if ($this->configuration?->isReleaseCycleAnalysisEnabled()) {
            $columns[] = 'cycle';
        }

        $columns[] = 'latest';

        return $columns;
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
            'package' => 'Package',
            'version' => 'Version',
            'age' => 'Age',
            'rating' => 'Rating',
            'cycle' => 'Activity',
            'latest' => 'Latest',
            'dev' => 'Dev',
        ];

        return array_map(static fn ($col) => $headerMap[$col] ?? ucfirst($col), $columns);
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
    private function formatTableRow(Package $package, array $rating, array $detailed, array $columns, ?ConsoleStyleKit $style = null): array
    {
        $row = [];

        foreach ($columns as $column) {
            $row[] = match ($column) {
                'package' => sprintf('%s (%s)', $package->name, $this->formatDependencyType($package)),
                'version' => $package->version,
                'age' => $rating['age_formatted'] ?? 'Unknown',
                'rating' => $this->formatRating($rating, $style),
                'latest' => $this->formatLatestVersion($package, $detailed) ?: '',
                'dev' => $package->isDev ? 'Yes' : '',
                'cycle' => $this->formatReleaseCycle($package, $style),
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
    private function formatSummaryRow(array $packages, array $columns, array $thresholds = [], ?DateTimeImmutable $referenceDate = null, ?ConsoleStyleKit $style = null): array
    {
        $row = [];
        $totalAge = $this->formatTotalPackageAge($packages, $referenceDate);
        $overallRating = $this->getOverallRating($packages, $thresholds, $referenceDate, $style);

        foreach ($columns as $column) {
            $row[] = match ($column) {
                'package' => '∑',
                'age' => '<options=bold>'.$totalAge.'</options=bold>',
                'rating' => $overallRating,
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
    private function formatRating(array $rating, ?ConsoleStyleKit $style = null): string
    {
        if (null === $style) {
            return match ($rating['category']) {
                'current' => '<fg=green>✓</fg=green>',
                'medium' => '<fg=yellow>~</fg=yellow>',
                'old' => '<fg=red>!</fg=red>',
                'unknown' => '<fg=gray>?</fg=gray>',
                default => '<fg=gray>?</fg=gray>',
            };
        }

        // Use ConsoleStyleKit rating system
        $maxRating = 3;
        $currentRating = match ($rating['category']) {
            'current' => 3,  // Best rating
            'medium' => 2,   // Medium rating
            'old' => 1,      // Poor rating
            'unknown' => 0,  // Unknown - shows as 0 of 3
            default => 0,    // Unknown - shows as 0 of 3
        };

        return RatingElement::circle($style, $maxRating, $currentRating, true)->__toString();
    }

    /**
     * Format dependency type with colors.
     */
    private function formatDependencyType(Package $package): string
    {
        if ($package->isDev && $package->isDirect) {
            return '<fg=magenta>*</>';
        }

        if ($package->isDev && !$package->isDirect) {
            return '<fg=magenta>*</><fg=white>~</>';
        }

        if ($package->isDirect) {
            return '<fg=cyan>→</>';
        }

        return '<fg=yellow>~</>';
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

        return sprintf('%s (%s)', $latestVersion, $this->formatImpact($package, $detailed));
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
     * Format release cycle activity.
     */
    private function formatReleaseCycle(Package $package, ?ConsoleStyleKit $style = null): string
    {
        if (null === $this->releaseCycleService) {
            return ''; // Release cycle analysis disabled
        }

        $cycleAnalysis = $this->releaseCycleService->analyzeReleaseCycle($package);

        return $this->releaseCycleService->formatCycleRating($cycleAnalysis, $style);
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
    private function renderLegend(InputInterface $input, OutputInterface $output): void
    {
        $style = new ConsoleStyleKit($input, $output);
        if (!$style->isVerbose()) {
            return;
        }

        $style->newLine();
        $output->writeln('<options=bold>Legend</>');
        $output->writeln('- Rating: '.RatingElement::circle($style, 3, 3, colorful: true)->__toString().'  mostly current, '.RatingElement::circle($style, 3, 2, colorful: true)->__toString().' moderately current, '.RatingElement::circle($style, 3, 1, colorful: true)->__toString().' chronologically old, '.RatingElement::circle($style, 3, 0, colorful: true)->__toString().' unknown');

        // Add Activity legend only if release cycle analysis is enabled
        if ($this->configuration?->isReleaseCycleAnalysisEnabled()) {
            $output->writeln('- Activity: '.RatingElement::circle($style, 3, 3, colorful: true)->__toString().' very active (≤60d), '.RatingElement::circle($style, 3, 2, colorful: true)->__toString().' active (≤180d), '.RatingElement::circle($style, 3, 1, colorful: true)->__toString().' moderate (≤365d), '.RatingElement::circle($style, 3, 0, colorful: true)->__toString().' slow/inactive');
        }

        $output->writeln('- Type: <fg=cyan>→</> direct dependency, <fg=yellow>~</> indirect dependency, <fg=magenta>*</> dev dependency');
    }

    /**
     * Render summary statistics.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     */
    private function renderSummary(array $packages, OutputInterface $output, InputInterface $input, array $thresholds = [], ?DateTimeImmutable $referenceDate = null, bool $directModeActive = false): void
    {
        if (empty($packages)) {
            return;
        }

        $style = new ConsoleStyleKit($input, $output);

        if (!$directModeActive) {
            $style->blockquote("By default, all composer dependencies are shown. For a focused analysis on direct dependencies only, use the <fg=cyan>`composer dependency-age --direct`</fg=cyan> option.\nFocusing on direct dependencies gives a clearer picture of your project's core dependency health.", BlockquoteType::IMPORTANT->value);
        }

        if ($output->isVerbose()) {
            $this->renderDevPackageExplanation($packages, $style);
        }

        $statistics = $this->ageCalculationService->calculateStatistics($packages, $referenceDate);

        $style->section('Summary');

        $summaryTable = new Table($output);
        $summaryTable->setHeaders(['Metric', 'Value']);

        $totalAge = $this->formatTotalPackageAge($packages, $referenceDate);
        $averageAge = $statistics['average_age_formatted'] ?? 'Unknown';
        $packageCounts = count($packages);
        $updateImpact = $this->formatTotalUpdateImpact($statistics);
        $overallRating = $this->getOverallRating($packages, $thresholds, $referenceDate, $style);

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
            $this->renderVerboseRatingExplanation($packages, $output, $style, $thresholds, $referenceDate);
        }
    }

    /**
     * Get overall project rating using RatingService.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     */
    private function getOverallRating(array $packages, array $thresholds = [], ?DateTimeImmutable $referenceDate = null, ?ConsoleStyleKit $style = null): string
    {
        $maxRating = 3;

        if (empty($packages)) {
            if (null === $style) {
                return '<fg=gray>?</fg=gray> unknown';
            }

            return RatingElement::circle($style, 3, 0, true)->__toString();
        }

        $summary = $this->ratingService->getRatingSummary($packages, $thresholds, $referenceDate);

        if (null === $style) {
            // Fallback to text rating for non-style contexts
            return $summary['overall_rating'] ?? '<fg=gray>?</fg=gray> unknown';
        }
        $percentages = $summary['percentages'] ?? [];
        $currentPercent = $percentages['current'] ?? 0;
        $oldPercent = $percentages['old'] ?? 0;
        $unknownPercent = $percentages['unknown'] ?? 0;
        $dominantCategory = $summary['dominant_category'] ?? 'unknown';

        // If all or most packages are unknown, show unknown rating
        if ('unknown' === $dominantCategory || $unknownPercent >= 70) {
            $currentRating = 0; // Unknown
        } elseif ($currentPercent >= 70) {
            $currentRating = 3; // Mostly current
        } elseif ($oldPercent >= 30) {
            $currentRating = 1; // Needs attention
        } else {
            $currentRating = 2; // Moderately current
        }

        return RatingElement::circle($style, $maxRating, $currentRating, true)->__toString();
    }

    /**
     * Render verbose explanation of how the overall rating is calculated.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     */
    private function renderVerboseRatingExplanation(array $packages, OutputInterface $output, ConsoleStyleKit $style, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): void
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

        $unknownPercent = round($percentages['unknown'] ?? 0, 1);
        $dominantCategory = $summary['dominant_category'] ?? 'unknown';

        if ('unknown' === $dominantCategory || $unknownPercent >= 70) {
            $ratingDisplay = RatingElement::circle($style, 3, 0, true)->__toString();
            $output->writeln(sprintf('  → %s Unknown: %.1f%% packages have unknown release dates ≥ 70%%', $ratingDisplay, $unknownPercent));
        } elseif ($currentPercent >= 70) {
            $ratingDisplay = RatingElement::circle($style, 3, 3, true)->__toString();
            $output->writeln(sprintf('  → %s Mostly Current: %.1f%% current packages ≥ 70%%', $ratingDisplay, $currentPercent));
        } elseif ($oldPercent >= 30) {
            $ratingDisplay = RatingElement::circle($style, 3, 1, true)->__toString();
            $output->writeln(sprintf('  → %s Needs Attention: %.1f%% old packages ≥ 30%%', $ratingDisplay, $oldPercent));
        } else {
            $ratingDisplay = RatingElement::circle($style, 3, 2, true)->__toString();
            $output->writeln(sprintf('  → %s Moderately Current: %.1f%% current < 70%% AND %.1f%% old < 30%%', $ratingDisplay, $currentPercent, $oldPercent));
        }
    }

    /**
     * Render explanation for development packages if present.
     *
     * @param array<Package> $packages
     */
    private function renderDevPackageExplanation(array $packages, ConsoleStyleKit $style): void
    {
        $devVersionPackages = [];

        foreach ($packages as $package) {
            if ($this->isDevVersion($package->version)) {
                $devVersionPackages[] = $package;
            }
        }

        if (empty($devVersionPackages)) {
            return;
        }

        $count = count($devVersionPackages);
        $total = count($packages);
        $percentage = round(($count / $total) * 100, 1);

        $message = sprintf(
            "%d of %d packages (%.1f%%) use development versions (e.g., dev-main, 1.x-dev) that don't have fixed release dates. These packages are marked as 'unknown' in the rating and are excluded from the overall project rating calculation. Development versions track the latest code changes but cannot be aged-analyzed since they don't have stable release timestamps.",
            $count,
            $total,
            $percentage,
        );

        $style->blockquote($message, BlockquoteType::WARNING->value);
    }

    /**
     * Check if a package version is a development version.
     */
    private function isDevVersion(string $version): bool
    {
        return str_starts_with($version, 'dev-')
            || str_ends_with($version, '-dev')
            || str_contains($version, '.x-dev');
    }
}
