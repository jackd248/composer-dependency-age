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
            ->setDescription('Analyze the age categories of your project dependencies')
            ->setHelp(
                <<<'HELP'
The <info>dependency-age</info> command analyzes the age of all dependencies in your project
and categorizes them based on their release dates. This provides neutral age categorization
without risk assessment to help you understand your dependency landscape.

<comment>Syntax:</comment>
<info>composer dependency-age [options]</info>

<comment>Examples:</comment>
<info>composer dependency-age</info>                           Basic analysis with table output
<info>composer dependency-age --format json</info>            JSON output for CI integration
<info>composer dependency-age --direct</info>                 Analyze only direct dependencies
<info>composer dependency-age --no-dev</info>                 Exclude development dependencies
<info>composer dependency-age --format github</info>          GitHub-formatted output for PRs

<comment>Age Categories:</comment>
• <fg=green>Current</fg=green>  - Dependencies released within 6 months (≤ 0.5 years)
• <fg=yellow>Medium</fg=yellow>   - Dependencies released within 12 months (≤ 1.0 year)
• <fg=red>Old</fg=red>      - Dependencies older than 12 months (> 1.0 year)
• <fg=gray>Unknown</fg=gray>  - Dependencies without release date information

<comment>Output Formats:</comment>
• <info>cli</info>     - Human-readable table with colors and summary (default)
• <info>json</info>    - Machine-readable JSON format for automation
• <info>github</info>  - Markdown format optimized for GitHub PRs/Issues

<comment>Configuration:</comment>
Configuration can be set via:
• Command line options (highest priority)
• composer.json "extra" section
• Environment variables
• Default values

Example composer.json configuration:
<info>{
  "extra": {
    "dependency-age": {
      "thresholds": {"current": 0.5, "medium": 1.0, "old": 2.0},
      "ignore": ["psr/log", "psr/container"],
      "output_format": "cli",
      "include_dev": false,
      "cache_ttl": 86400
    }
  }
}</info>

<comment>Cache Management:</comment>
The plugin caches Packagist API responses to improve performance:
• Default cache file: <info>.dependency-age.cache</info>
• Cache TTL: 24 hours (configurable)
• Use <info>--no-cache</info> to disable caching
• Use <info>--offline</info> for offline mode (cache-only)

<comment>Filtering Options:</comment>
• <info>--direct</info>     Show only direct dependencies (not transitive)
• <info>--no-dev</info>     Exclude development dependencies
• <info>--ignore</info>     Additional packages to ignore (comma-separated)

<comment>Display Options:</comment>
• <info>--no-colors</info>   Disable color output
• <info>--format</info>      Output format: cli, json, github (default: cli)
• <info>--thresholds</info>  Custom age thresholds in years

<comment>Performance Options:</comment>
• <info>--api-timeout</info>      API request timeout (default: 30 seconds)
• <info>--cache-ttl</info>        Cache lifetime in seconds (default: 86400)
• <info>--cache-file</info>       Custom cache file path (default: .dependency-age.cache)

<comment>Integration:</comment>
The plugin can be used in CI/CD pipelines:
• Exit code 0: Analysis completed successfully
• Exit code 1: Configuration or execution errors
• Use JSON format for automated processing
• GitHub format for pull request comments

<comment>Notes:</comment>
This tool provides neutral age analysis only. It does not assess security
risks, compatibility issues, or update recommendations. Use the information
to understand your dependency landscape and make informed decisions.
HELP
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
                'Age thresholds in format "current=0.5,medium=1.0,old=2.0" (years)',
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
                'direct',
                null,
                InputOption::VALUE_NONE,
                'Show only direct dependencies (exclude transitive dependencies)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Display header as specified in requirements
        $this->displayHeader($output);

        $output->writeln('');
        $output->writeln('<fg=gray>Analyzing dependency ages...</>');

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
            $filteredPackages = array_filter($packages, function ($package) use ($config, $input) {
                // Skip dev packages if not included
                if (!$config->shouldIncludeDev() && $package->isDev) {
                    return false;
                }

                // Skip ignored packages
                if ($config->isPackageIgnored($package->name)) {
                    return false;
                }

                // Filter for direct dependencies only if --direct option is used
                if ($input->getOption('direct') && !$package->isDirect) {
                    return false;
                }

                return true;
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
                    'direct_mode_active' => $input->getOption('direct'),
                ], $thresholds);
            } else {
                $formattedOutput = $outputManager->format($format, $enrichedPackages, [
                    'show_colors' => $showColors,
                ], $thresholds);
                $output->write($formattedOutput);
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
        $output->writeln('');
        $output->writeln('<comment>Composer Dependency Age</comment>');
        $output->writeln('<comment>===================================</comment>');
        $output->writeln('');
        $output->writeln(' A Composer plugin for neutral analysis of your project dependencies\' age.');
        $output->writeln('');
        $output->writeln(' For more information: <fg=cyan>composer dependency-age --verbose</>');
    }
}
