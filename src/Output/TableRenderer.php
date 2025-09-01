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
 * Service for rendering package data as CLI tables.
 */
class TableRenderer
{
    private const DEFAULT_COLUMN_WIDTHS = [
        'package' => 25,
        'version' => 12,
        'age' => 12,
        'rating' => 10,
        'latest' => 12,
        'impact' => 12,
        'notes' => 20,
    ];

    public function __construct(
        private readonly AgeCalculationService $ageCalculationService,
        private readonly RatingService $ratingService,
        private readonly ?ColorFormatter $colorFormatter = null,
    ) {}

    /**
     * Render packages as a formatted CLI table.
     *
     * @param array<Package>       $packages
     * @param array<string, mixed> $options
     */
    public function renderTable(array $packages, array $options = []): string
    {
        if (empty($packages)) {
            return "No packages found.\n";
        }

        $columns = $options['columns'] ?? $this->getDefaultColumns();
        $thresholds = $options['thresholds'] ?? [];
        $referenceDate = $options['reference_date'] ?? null;
        $showColors = $options['show_colors'] ?? true;

        $tableData = $this->prepareTableData($packages, $columns, $thresholds, $referenceDate);

        return $this->formatTable($tableData, $columns, $showColors);
    }

    /**
     * Render a compact summary table.
     *
     * @param array<Package>       $packages
     * @param array<string, mixed> $options
     */
    public function renderSummaryTable(array $packages, array $options = []): string
    {
        if (empty($packages)) {
            return "No packages found.\n";
        }

        $thresholds = $options['thresholds'] ?? [];
        $referenceDate = $options['reference_date'] ?? null;
        $showColors = $options['show_colors'] ?? true;

        $summary = $this->ratingService->getRatingSummary($packages, $thresholds, $referenceDate);
        $statistics = $this->ageCalculationService->calculateStatistics($packages, $referenceDate);

        $output = [];
        $output[] = $this->formatSummaryHeader('Dependency Age Summary', $showColors);
        $output[] = '';
        $output[] = $this->formatSummaryStats($summary, $statistics, $showColors);
        $output[] = '';

        return implode("\n", $output);
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
     * Get available columns.
     *
     * @return array<string, string>
     */
    public function getAvailableColumns(): array
    {
        return [
            'package' => 'Package Name',
            'version' => 'Installed Version',
            'age' => 'Age',
            'rating' => 'Rating',
            'latest' => 'Latest Version',
            'impact' => 'Update Impact',
            'notes' => 'Notes',
            'dev' => 'Dev Dependency',
        ];
    }

    /**
     * Prepare table data from packages.
     *
     * @param array<Package>       $packages
     * @param array<string>        $columns
     * @param array<string, mixed> $thresholds
     *
     * @return array<array<string, string>>
     */
    private function prepareTableData(array $packages, array $columns, array $thresholds, ?DateTimeImmutable $referenceDate): array
    {
        $data = [];

        foreach ($packages as $package) {
            $rating = $this->ratingService->ratePackage($package, $thresholds, $referenceDate);
            $detailed = $this->ratingService->getDetailedRating($package, $thresholds, $referenceDate);

            $row = [];

            foreach ($columns as $column) {
                $row[$column] = match ($column) {
                    'package' => $package->name,
                    'version' => $package->version,
                    'age' => $rating['age_formatted'] ?? 'Unknown',
                    'rating' => $this->formatRating($rating, true),
                    'latest' => $detailed['latest_info']['version'] ?? '-',
                    'impact' => $this->formatImpact($detailed),
                    'notes' => $this->formatNotes($package, $detailed),
                    'dev' => $package->isDev ? 'Yes' : 'No',
                    default => '-',
                };
            }

            $data[] = $row;
        }

        return $data;
    }

    /**
     * Format the table with headers and data.
     *
     * @param array<array<string, string>> $data
     * @param array<string>                $columns
     */
    private function formatTable(array $data, array $columns, bool $showColors): string
    {
        if (empty($data)) {
            return "No data to display.\n";
        }

        // Calculate column widths
        $widths = $this->calculateColumnWidths($data, $columns);

        $output = [];

        // Header
        $output[] = $this->formatTableHeader($columns, $widths, $showColors);
        $output[] = $this->formatTableSeparator($widths);

        // Data rows
        foreach ($data as $row) {
            $output[] = $this->formatTableRow($row, $columns, $widths);
        }

        $output[] = $this->formatTableSeparator($widths);

        return implode("\n", $output)."\n";
    }

    /**
     * Calculate optimal column widths based on content.
     *
     * @param array<array<string, string>> $data
     * @param array<string>                $columns
     *
     * @return array<string, int>
     */
    private function calculateColumnWidths(array $data, array $columns): array
    {
        $widths = [];

        foreach ($columns as $column) {
            $columnName = $this->getAvailableColumns()[$column] ?? ucfirst((string) $column);
            $headerWidth = strlen($columnName);

            $maxContentWidth = 0;
            foreach ($data as $row) {
                $content = $row[$column] ?? '';
                $contentWidth = strlen(strip_tags($content)); // Remove any color codes
                $maxContentWidth = max($maxContentWidth, $contentWidth);
            }

            $defaultWidth = self::DEFAULT_COLUMN_WIDTHS[$column] ?? 15;
            $widths[$column] = max($headerWidth, $maxContentWidth, $defaultWidth);
        }

        return $widths;
    }

    /**
     * Format table header.
     *
     * @param array<string>      $columns
     * @param array<string, int> $widths
     */
    private function formatTableHeader(array $columns, array $widths, bool $showColors): string
    {
        $headers = [];

        foreach ($columns as $column) {
            $header = $this->getAvailableColumns()[$column] ?? ucfirst((string) $column);
            $headers[] = str_pad($header, $widths[$column]);
        }

        $headerRow = implode(' | ', $headers);

        if ($showColors && $this->colorFormatter) {
            return $this->colorFormatter->style($headerRow, 'bold');
        }

        return $showColors ? "\033[1m{$headerRow}\033[0m" : $headerRow; // Fallback to ANSI if no formatter
    }

    /**
     * Format table separator line.
     *
     * @param array<string, int> $widths
     */
    private function formatTableSeparator(array $widths): string
    {
        $separators = [];

        foreach ($widths as $width) {
            $separators[] = str_repeat('-', $width);
        }

        return implode('-|-', $separators);
    }

    /**
     * Format a table row.
     *
     * @param array<string, string> $row
     * @param array<string>         $columns
     * @param array<string, int>    $widths
     */
    private function formatTableRow(array $row, array $columns, array $widths): string
    {
        $cells = [];

        foreach ($columns as $column) {
            $content = $row[$column] ?? '-';
            $cells[] = str_pad($content, $widths[$column]);
        }

        return implode(' | ', $cells);
    }

    /**
     * Format rating with emoji and color.
     *
     * @param array<string, mixed> $rating
     */
    private function formatRating(array $rating, bool $withEmoji = true): string
    {
        if ('unknown' === $rating['category']) {
            $text = $withEmoji ? '⚪ Unknown' : 'Unknown';

            return $this->colorFormatter ? $this->colorFormatter->muted($text) : $text;
        }

        $emoji = $withEmoji ? $rating['emoji'].' ' : '';
        $description = match ($rating['category']) {
            'green' => 'Current',
            'yellow' => 'Outdated',
            'red' => 'Critical',
            default => 'Unknown',
        };

        $text = $emoji.$description;

        if ($this->colorFormatter) {
            return $this->colorFormatter->formatRating($text, $rating['category']);
        }

        return $text;
    }

    /**
     * Format update impact.
     *
     * @param array<string, mixed> $detailed
     */
    private function formatImpact(array $detailed): string
    {
        if (null === $detailed['age_reduction']) {
            return '-';
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

        if (null !== $detailed['latest_info']) {
            $notes[] = 'Update available';
        }

        if ('red' === $detailed['rating']['category']) {
            $notes[] = 'Critical';
        }

        return empty($notes) ? '-' : implode(', ', $notes);
    }

    /**
     * Format summary header.
     */
    private function formatSummaryHeader(string $title, bool $showColors): string
    {
        $separator = str_repeat('=', strlen($title));

        if ($showColors && $this->colorFormatter) {
            $formattedTitle = $this->colorFormatter->header($title);

            return "{$formattedTitle}\n{$separator}";
        }

        if ($showColors) {
            return "\033[1m{$title}\033[0m\n{$separator}";
        }

        return "{$title}\n{$separator}";
    }

    /**
     * Format summary statistics.
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $statistics
     */
    private function formatSummaryStats(array $summary, array $statistics, bool $showColors): string
    {
        $output = [];

        // Distribution
        $total = $summary['total_packages'];
        if ($total > 0) {
            $output[] = "Total packages: {$total}";
            $output[] = sprintf('🟢 Current: %d (%.1f%%)', $summary['distribution']['green'], $summary['percentages']['green']);
            $output[] = sprintf('🟡 Outdated: %d (%.1f%%)', $summary['distribution']['yellow'], $summary['percentages']['yellow']);
            $output[] = sprintf('🔴 Critical: %d (%.1f%%)', $summary['distribution']['red'], $summary['percentages']['red']);

            if ($summary['distribution']['unknown'] > 0) {
                $output[] = sprintf('⚪ Unknown: %d (%.1f%%)', $summary['distribution']['unknown'], $summary['percentages']['unknown']);
            }

            $output[] = sprintf('Health score: %.1f%%', $summary['health_score']);
        }

        // Age statistics
        if (null !== $statistics['average_age_days']) {
            $output[] = '';
            $output[] = 'Age statistics:';
            $output[] = sprintf('Average age: %s', $statistics['average_age_formatted']);
            $output[] = sprintf('Oldest package: %s', $this->ageCalculationService->formatAge((int) $statistics['oldest_age_days']));
            $output[] = sprintf('Newest package: %s', $this->ageCalculationService->formatAge((int) $statistics['newest_age_days']));
        }

        if (null !== $statistics['potential_reduction_days']) {
            $potentialReduction = $this->ageCalculationService->formatAge((int) $statistics['potential_reduction_days']);
            $output[] = sprintf('Potential age reduction: %s', $potentialReduction);
        }

        return implode("\n", $output);
    }

    /**
     * Set custom column widths.
     *
     * @param array<string, int> $widths
     */
    public function setColumnWidths(array $widths): self
    {
        // This could be used for custom width configuration
        return $this;
    }
}
