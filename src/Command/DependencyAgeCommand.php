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
use KonradMichalik\ComposerDependencyAge\Configuration\ConfigurationLoader;
use KonradMichalik\ComposerDependencyAge\Configuration\WhitelistService;
use KonradMichalik\ComposerDependencyAge\Output\OutputManager;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\CachePathService;
use KonradMichalik\ComposerDependencyAge\Service\CacheService;
use KonradMichalik\ComposerDependencyAge\Service\PackageInfoService;
use KonradMichalik\ComposerDependencyAge\Service\PerformanceOptimizationService;
use KonradMichalik\ComposerDependencyAge\Service\RatingService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

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
                'cli',
            )
            ->addOption(
                'no-colors',
                null,
                InputOption::VALUE_NONE,
                'Disable color output',
            )
            ->addOption(
                'no-dev',
                null,
                InputOption::VALUE_NONE,
                'Exclude dev dependencies from analysis',
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable cache usage',
            )
            ->addOption(
                'offline',
                null,
                InputOption::VALUE_NONE,
                'Offline mode - use only cached data (no API calls)',
            )
            ->addOption(
                'ignore',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of packages to ignore (in addition to config)',
            )
            ->addOption(
                'thresholds',
                null,
                InputOption::VALUE_REQUIRED,
                'Age thresholds in format "green=0.5,yellow=1.0,red=2.0" (years)',
            )
            ->addOption(
                'cache-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Custom cache file path',
            )
            ->addOption(
                'api-timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'API request timeout in seconds',
            )
            ->addOption(
                'cache-ttl',
                null,
                InputOption::VALUE_REQUIRED,
                'Cache TTL in seconds',
            )
            ->addOption(
                'fail-on-critical',
                null,
                InputOption::VALUE_NONE,
                'Exit with code 2 if critical dependencies found',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Display header as specified in requirements
        $this->displayHeader($output);

        $output->writeln('');
        $output->writeln('<comment>Analyzing dependency ages...</comment>');

        try {
            $composer = $this->requireComposer();

            // Load configuration
            $whitelistService = new WhitelistService();
            $configurationLoader = new ConfigurationLoader($whitelistService);
            $config = $configurationLoader->load($composer, $input);

            // Parse command options
            $offlineMode = $input->getOption('offline');
            $noCacheMode = $input->getOption('no-cache');
            $maxConcurrent = $config->getMaxConcurrentRequests();

            // Initialize performance service
            $performanceService = new PerformanceOptimizationService();

            // Check if system is offline and enable offline mode if needed
            if (!$offlineMode && $performanceService->isSystemOffline()) {
                $output->writeln('<comment>System appears to be offline, enabling offline mode...</comment>');
                $offlineMode = true;
            }

            // Initialize API client with performance settings
            $packagistClient = new PackagistClient(
                timeout: $config->getApiTimeout(),
                maxConcurrentRequests: $maxConcurrent,
                retryAttempts: 3,
                retryDelayMultiplier: 1.5,
                respectRateLimit: true,
            );

            // Initialize services
            $ageCalculationService = new AgeCalculationService();
            $ratingService = new RatingService($ageCalculationService);

            // Initialize cache service unless disabled
            $cacheService = null;
            if (!$noCacheMode) {
                $cacheFile = $config->getCacheFile();
                // If cache file is absolute path, use as-is, otherwise resolve it
                if (!str_starts_with($cacheFile, '/')) {
                    $cachePathService = new CachePathService();
                    $cacheFile = $cachePathService->getCacheFilePath();
                    // Replace default filename with configured filename
                    $cacheFile = dirname($cacheFile).'/'.basename($config->getCacheFile());
                }
                $cacheService = new CacheService($cacheFile, $config->getCacheTtl());
            } elseif ($offlineMode) {
                $output->writeln('<error>Error: Cannot use offline mode without cache (--offline requires caching)</error>');

                return self::FAILURE;
            }

            $packageInfoService = new PackageInfoService(
                $packagistClient,
                $cacheService,
                $performanceService,
                $offlineMode,
            );
            $outputManager = new OutputManager($ageCalculationService, $ratingService);

            // Get packages from composer.lock
            $packages = $packageInfoService->getInstalledPackages($composer);

            // Filter ignored packages and dev packages if needed
            $filteredPackages = array_filter($packages, function ($package) use ($config) {
                // Skip dev packages if not included
                if (!$config->shouldIncludeDev() && $package->isDev) {
                    return false;
                }

                // Skip ignored packages
                return !$config->isPackageIgnored($package->name);
            });

            if (empty($filteredPackages)) {
                $output->writeln('<comment>No packages found to analyze after filtering.</comment>');

                return self::SUCCESS;
            }

            $output->writeln(sprintf('<info>Found %d packages to analyze.</info>', count($filteredPackages)));

            // Fetch package information with progress
            $enrichedPackages = $packageInfoService->enrichPackagesWithReleaseInfo($filteredPackages);

            // Format and output results
            $format = $config->getOutputFormat();
            $showColors = $config->shouldShowColors();
            $thresholds = $config->getThresholds();

            // Use different rendering for CLI format
            if ('cli' === $format) {
                $outputManager->renderCliTable($enrichedPackages, $output, [
                    'show_colors' => $showColors,
                ], $thresholds);
            } else {
                $formattedOutput = $outputManager->format($format, $enrichedPackages, [
                    'show_colors' => $showColors,
                ], $thresholds);
                $output->write($formattedOutput);
            }

            // Check if we should fail on critical dependencies
            if ($config->shouldFailOnCritical()) {
                $summary = $ratingService->getRatingSummary($enrichedPackages, $thresholds);
                if ($summary['has_critical']) {
                    return 2; // Exit code 2 for critical dependencies
                }
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Error: '.$e->getMessage().'</error>');
            if ($output->isVerbose()) {
                $output->writeln('<error>'.$e->getTraceAsString().'</error>');
            }

            return self::FAILURE;
        }
    }

    /**
     * Display command header as specified in requirements.
     */
    private function displayHeader(OutputInterface $output): void
    {
        $output->writeln('<info>Composer Dependency Age</info>');
        $output->writeln('<info>==============================</info>');
        $output->writeln('');
        $output->writeln(' A Composer plugin that analyzes the age of your project dependencies.');
        $output->writeln('');
        $output->writeln(' For more information and usage examples, run: `composer dependency-age --help`');
    }
}
