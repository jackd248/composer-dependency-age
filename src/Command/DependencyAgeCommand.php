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

namespace KonradMichalik\ComposerDependencyAge\Command;

use Composer\Command\BaseCommand;
use KonradMichalik\ComposerDependencyAge\Api\PackagistClient;
use KonradMichalik\ComposerDependencyAge\Output\TableRenderer;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\PackageInfoService;
use KonradMichalik\ComposerDependencyAge\Service\RatingService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Main command for analyzing dependency ages.
 */
final class DependencyAgeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('dependency-age')
            ->setDescription('Analyze the age of your project dependencies')
            ->setHelp(
                'This command analyzes the age of all dependencies in your project and '.
                'provides insights about outdated packages.',
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: cli (default), json, github',
                'cli'
            )
            ->addOption(
                'no-colors',
                null,
                InputOption::VALUE_NONE,
                'Disable color output'
            )
            ->addOption(
                'no-dev',
                null,
                InputOption::VALUE_NONE,
                'Exclude dev dependencies from analysis'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Composer Dependency Age Plugin v0.1.0</info>');
        $output->writeln('<comment>Analyzing dependency ages...</comment>');

        try {
            $composer = $this->requireComposer();
            
            // Initialize services
            $packagistClient = new PackagistClient();
            $ageCalculationService = new AgeCalculationService();
            $ratingService = new RatingService($ageCalculationService);
            $packageInfoService = new PackageInfoService($packagistClient);
            $tableRenderer = new TableRenderer($ageCalculationService, $ratingService);

            // Get packages from composer.lock
            $packages = $packageInfoService->getInstalledPackages($composer);
            
            if (empty($packages)) {
                $output->writeln('<comment>No packages found to analyze.</comment>');
                return self::SUCCESS;
            }

            $output->writeln(sprintf('<info>Found %d packages to analyze.</info>', count($packages)));

            // Fetch package information with progress
            $enrichedPackages = $packageInfoService->enrichPackagesWithReleaseInfo($packages);
            
            // Rate packages
            $ratings = $ratingService->ratePackages($enrichedPackages);
            
            // Get output format
            $format = $input->getOption('format') ?? 'cli';
            $showColors = !$input->getOption('no-colors');
            
            // Render output
            if ($format === 'cli') {
                $tableOutput = $tableRenderer->renderTable($enrichedPackages, [
                    'show_colors' => $showColors,
                ]);
                $output->write($tableOutput);
                
                // Show summary
                $summary = $ratingService->getRatingSummary($enrichedPackages);
                $output->writeln('');
                $output->writeln('<info>Summary:</info>');
                $output->writeln(sprintf('  Total packages: %d', $summary['total_packages']));
                $output->writeln(sprintf('  Health score: %.1f%%', $summary['health_score']));
                if ($summary['has_critical']) {
                    $output->writeln('<error>  ⚠️  Critical packages found!</error>');
                }
            } elseif ($format === 'json') {
                $jsonData = [
                    'summary' => $ratingService->getRatingSummary($enrichedPackages),
                    'packages' => $ratings,
                ];
                $jsonOutput = json_encode($jsonData, JSON_PRETTY_PRINT);
                if (false === $jsonOutput) {
                    throw new \RuntimeException('Failed to encode JSON output');
                }
                $output->writeln($jsonOutput);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            if ($output->isVerbose()) {
                $output->writeln('<error>' . $e->getTraceAsString() . '</error>');
            }
            return self::FAILURE;
        }
    }
}
