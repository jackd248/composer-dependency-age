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
use InvalidArgumentException;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\RatingService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OutputManager.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class OutputManager
{
    private readonly TableRenderer $tableRenderer;
    private readonly JsonFormatter $jsonFormatter;
    private readonly GitHubFormatter $gitHubFormatter;

    public function __construct(
        AgeCalculationService $ageCalculationService,
        RatingService $ratingService,
    ) {
        $this->tableRenderer = new TableRenderer($ageCalculationService, $ratingService);
        $this->jsonFormatter = new JsonFormatter($ageCalculationService, $ratingService);
        $this->gitHubFormatter = new GitHubFormatter($ageCalculationService, $ratingService);
    }

    /**
     * Format packages using the specified output format.
     *
     * @param array<Package>       $packages
     * @param array<string, mixed> $options
     * @param array<string, float> $thresholds
     */
    public function format(
        string $format,
        array $packages,
        array $options = [],
        array $thresholds = [],
        ?DateTimeImmutable $referenceDate = null,
    ): string {
        return match ($format) {
            'json' => $this->formatJson($packages, $thresholds, $referenceDate),
            'github' => $this->formatGitHub($packages, $thresholds, $referenceDate),
            default => throw new InvalidArgumentException("Unsupported output format: {$format}. Use renderCliTable() for CLI output."),
        };
    }

    /**
     * Render CLI table directly to output (uses Symfony Console Table).
     *
     * @param array<Package>       $packages
     * @param array<string, mixed> $options
     * @param array<string, float> $thresholds
     */
    public function renderCliTable(
        array $packages,
        OutputInterface $output,
        InputInterface $input,
        array $options = [],
        array $thresholds = [],
        ?DateTimeImmutable $referenceDate = null,
    ): void {
        $this->tableRenderer->renderTable($packages, $output, $input, $options, $thresholds, $referenceDate);
    }

    /**
     * Get structured data for programmatic use.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     *
     * @return array<string, mixed>
     */
    public function getStructuredData(array $packages, array $thresholds = [], ?DateTimeImmutable $referenceDate = null): array
    {
        return $this->jsonFormatter->formatAsArray($packages, $thresholds, $referenceDate);
    }

    /**
     * Get available output formats.
     *
     * @return array<string>
     */
    public static function getAvailableFormats(): array
    {
        return ['cli', 'json', 'github'];
    }

    /**
     * Check if format is supported.
     */
    public static function isFormatSupported(string $format): bool
    {
        return in_array($format, self::getAvailableFormats(), true);
    }

    /**
     * Format packages as JSON.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     */
    private function formatJson(array $packages, array $thresholds, ?DateTimeImmutable $referenceDate): string
    {
        return $this->jsonFormatter->format($packages, $thresholds, $referenceDate);
    }

    /**
     * Format packages as GitHub Markdown.
     *
     * @param array<Package>       $packages
     * @param array<string, float> $thresholds
     */
    private function formatGitHub(array $packages, array $thresholds, ?DateTimeImmutable $referenceDate): string
    {
        return $this->gitHubFormatter->format($packages, $thresholds, $referenceDate);
    }
}
