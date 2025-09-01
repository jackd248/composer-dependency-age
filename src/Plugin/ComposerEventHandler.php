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

namespace KonradMichalik\ComposerDependencyAge\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use KonradMichalik\ComposerDependencyAge\Api\PackagistClient;
use KonradMichalik\ComposerDependencyAge\Configuration\Configuration;
use KonradMichalik\ComposerDependencyAge\Configuration\ConfigurationLoader;
use KonradMichalik\ComposerDependencyAge\Configuration\WhitelistService;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\CachePathService;
use KonradMichalik\ComposerDependencyAge\Service\CacheService;
use KonradMichalik\ComposerDependencyAge\Service\PackageInfoService;
use KonradMichalik\ComposerDependencyAge\Service\RatingService;
use Symfony\Component\Console\Input\ArrayInput;
use Throwable;

/**
 * Handles Composer events for dependency age analysis.
 */
final class ComposerEventHandler
{
    private readonly Configuration $config;

    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
        ?Configuration $config = null,
    ) {
        // Load configuration early
        $this->config = $config ?? $this->loadConfiguration();
    }

    /**
     * Handle post-operation events (install/update).
     */
    public function handlePostOperation(string $operation): void
    {
        // Check if event integration is disabled
        if (!$this->shouldRunEventCheck($operation)) {
            return;
        }

        $this->io->write('');
        $this->io->write('📊 <info>Analyzing dependency ages...</info>');

        try {
            $summary = $this->performQuickAnalysis();
            $this->displaySummary($summary, $operation);
        } catch (Throwable $e) {
            $this->io->writeError('<warning>Dependency age analysis failed: '.$e->getMessage().'</warning>');

            if ($this->io->isVerbose()) {
                $this->io->writeError('<warning>'.$e->getTraceAsString().'</warning>');
            }
        }
    }

    /**
     * Perform quick dependency analysis optimized for post-operation hooks.
     *
     * @return array<string, mixed>
     */
    private function performQuickAnalysis(): array
    {
        // Initialize services with optimized settings for event hooks
        $packagistClient = new PackagistClient();
        $ageCalculationService = new AgeCalculationService();
        $ratingService = new RatingService($ageCalculationService);

        // Initialize cache service
        $cacheService = null;
        $cacheFile = $this->config->getCacheFile();
        if (!str_starts_with($cacheFile, '/')) {
            $cachePathService = new CachePathService();
            $cacheFile = $cachePathService->getCacheFilePath();
            $cacheFile = dirname($cacheFile).'/'.basename($this->config->getCacheFile());
        }
        $cacheService = new CacheService($cacheFile, $this->config->getCacheTtl());

        $packageInfoService = new PackageInfoService($packagistClient, $cacheService);

        // Get packages (limit for performance in event hooks)
        $packages = $packageInfoService->getInstalledPackages($this->composer);

        // Filter packages
        $filteredPackages = array_filter($packages, function ($package) {
            if (!$this->config->shouldIncludeDev() && $package->isDev) {
                return false;
            }

            return !$this->config->isPackageIgnored($package->name);
        });

        if (empty($filteredPackages)) {
            return [
                'total_packages' => 0,
                'health_score' => 100.0,
                'has_critical' => false,
                'critical_count' => 0,
                'average_age_formatted' => '0 days',
            ];
        }

        // For event hooks, we'll do a quick analysis with cache-first strategy
        // Only enrich packages that are not in cache to keep it fast
        $quickEnrichedPackages = $this->performQuickEnrichment($packageInfoService, $filteredPackages);

        // Get summary
        $summary = $ratingService->getRatingSummary($quickEnrichedPackages, $this->config->getThresholds());

        return $summary;
    }

    /**
     * Perform quick enrichment prioritizing cached data.
     *
     * @param array<\KonradMichalik\ComposerDependencyAge\Model\Package> $packages
     *
     * @return array<\KonradMichalik\ComposerDependencyAge\Model\Package>
     */
    private function performQuickEnrichment(PackageInfoService $packageInfoService, array $packages): array
    {
        $enrichedPackages = [];
        $packagesToEnrich = [];

        // First pass: Use cached data where available
        foreach ($packages as $package) {
            if (null !== $package->releaseDate) {
                // Package already has release date, use as-is
                $enrichedPackages[] = $package;
            } else {
                // Queue for enrichment
                $packagesToEnrich[] = $package;
            }
        }

        // Limit API calls for performance (max 10 packages per event)
        $packagesToEnrich = array_slice($packagesToEnrich, 0, 10);

        // Second pass: Enrich remaining packages
        if (!empty($packagesToEnrich)) {
            $newlyEnriched = $packageInfoService->enrichPackagesWithReleaseInfo($packagesToEnrich);
            $enrichedPackages = array_merge($enrichedPackages, $newlyEnriched);
        }

        return $enrichedPackages;
    }

    /**
     * Display summary after composer operations.
     *
     * @param array<string, mixed> $summary
     */
    private function displaySummary(array $summary, string $operation): void
    {
        $totalPackages = $summary['total_packages'];
        $healthScore = $summary['health_score'] ?? 100.0;
        $hasCritical = $summary['has_critical'] ?? false;
        $criticalCount = $summary['critical_count'] ?? 0;

        if (0 === $totalPackages) {
            $this->io->write('<comment>No packages to analyze.</comment>');

            return;
        }

        // Main summary line
        $healthEmoji = $this->getHealthEmoji($healthScore);
        $this->io->write(sprintf(
            '%s Your dependencies health score: <info>%.1f%%</info> (%d packages analyzed)',
            $healthEmoji,
            $healthScore,
            $totalPackages,
        ));

        // Show critical packages warning if any
        if ($hasCritical && $criticalCount > 0) {
            $this->io->write(sprintf(
                '<error>⚠️  Found %d critical package%s that need attention!</error>',
                $criticalCount,
                $criticalCount > 1 ? 's' : '',
            ));
        }

        // Show average age if available
        if (isset($summary['average_age_formatted']) && '0 days' !== $summary['average_age_formatted']) {
            $this->io->write(sprintf(
                'Average dependency age: <comment>%s</comment>',
                $summary['average_age_formatted'],
            ));
        }

        // Suggestion for detailed analysis
        if ($hasCritical || $healthScore < 80) {
            $this->io->write('');
            $this->io->write('💡 <comment>Run `composer dependency-age` for detailed analysis and recommendations.</comment>');
        }

        $this->io->write('');
    }

    /**
     * Check if event check should run based on configuration.
     */
    private function shouldRunEventCheck(string $operation): bool
    {
        // Check if event integration is disabled
        if (!$this->config->isEventIntegrationEnabled()) {
            return false;
        }

        // Check operation-specific settings
        $allowedOperations = $this->config->getEventOperations();

        return in_array($operation, $allowedOperations, true);
    }

    /**
     * Load configuration using existing configuration loader.
     */
    private function loadConfiguration(): Configuration
    {
        try {
            $whitelistService = new WhitelistService();
            $configurationLoader = new ConfigurationLoader($whitelistService);

            // Create a mock input for configuration loading
            $input = new ArrayInput([]);

            return $configurationLoader->load($this->composer, $input);
        } catch (Throwable) {
            // Fall back to default configuration if loading fails
            return new Configuration();
        }
    }

    /**
     * Get emoji based on health score.
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
}
