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

namespace KonradMichalik\ComposerDependencyAge\Service;

use Composer\Composer;
use DateTimeImmutable;
use KonradMichalik\ComposerDependencyAge\Api\PackagistClient;
use KonradMichalik\ComposerDependencyAge\Exception\ApiException;
use KonradMichalik\ComposerDependencyAge\Exception\PackageInfoException;
use KonradMichalik\ComposerDependencyAge\Model\Package;

/**
 * PackageInfoService.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
class PackageInfoService
{
    public function __construct(
        private readonly PackagistClient $client,
        private readonly ?CacheService $cacheService = null,
        private readonly ?PerformanceOptimizationService $performanceService = null,
        private readonly bool $offlineMode = false,
    ) {}

    /**
     * Get installed packages from composer instance.
     *
     * @return array<Package>
     */
    public function getInstalledPackages(Composer $composer): array
    {
        $packages = [];
        $installedRepository = $composer->getRepositoryManager()->getLocalRepository();
        $dependencyInfo = $this->getDependencyInfo($composer);

        foreach ($installedRepository->getPackages() as $composerPackage) {
            $packageName = $composerPackage->getName();
            $isDirect = isset($dependencyInfo['direct'][$packageName]) || isset($dependencyInfo['dev'][$packageName]);
            $isDev = isset($dependencyInfo['dev'][$packageName]);

            $packages[] = new Package(
                $packageName,
                $composerPackage->getPrettyVersion(),
                $isDev,
                $isDirect,
            );
        }

        return $packages;
    }

    /**
     * Enrich a package with release date information.
     *
     * @throws PackageInfoException
     */
    public function enrichPackageWithReleaseInfo(Package $package): Package
    {
        try {
            // Check cache first
            if (null !== $this->cacheService) {
                $cachedData = $this->cacheService->getPackageInfo($package->name, $package->version);
                if (null !== $cachedData) {
                    return $this->createPackageFromCachedData($package, $cachedData);
                }
            }

            $apiResponse = $this->client->getPackageInfo($package->name);

            if (!isset($apiResponse['packages'][$package->name])) {
                throw new PackageInfoException("Package '{$package->name}' not found in Packagist response");
            }

            $versions = $apiResponse['packages'][$package->name];

            // Find the specific version and latest version
            $installedVersionInfo = $this->findVersionInfo($versions, $package->version);
            $latestVersionInfo = $this->findLatestStableVersion($versions);

            if (null === $installedVersionInfo) {
                throw new PackageInfoException("Version '{$package->version}' not found for package '{$package->name}'");
            }

            $enrichedPackage = $package;

            // Add release date for installed version
            if (isset($installedVersionInfo['time'])) {
                $releaseDate = new DateTimeImmutable($installedVersionInfo['time']);
                $enrichedPackage = $enrichedPackage->withReleaseDate($releaseDate);
            }

            // Add latest version information
            if (null !== $latestVersionInfo && isset($latestVersionInfo['version'], $latestVersionInfo['time'])) {
                $latestReleaseDate = new DateTimeImmutable($latestVersionInfo['time']);
                $enrichedPackage = $enrichedPackage->withLatestVersion(
                    $latestVersionInfo['version'],
                    $latestReleaseDate,
                );
            }

            // Store in cache if cache service is available
            if (null !== $this->cacheService) {
                $cacheData = $this->createCacheDataFromPackage($enrichedPackage);
                $this->cacheService->storePackageInfo($package->name, $package->version, $cacheData);
            }

            return $enrichedPackage;
        } catch (ApiException $e) {
            throw new PackageInfoException("Failed to get package info for '{$package->name}': {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Enrich multiple packages with release information.
     * Skips packages that are not available on Packagist without throwing errors.
     *
     * @param array<Package> $packages
     *
     * @return array<Package>
     */
    public function enrichPackagesWithReleaseInfo(array $packages): array
    {
        if ($this->offlineMode) {
            return $this->enrichPackagesOfflineMode($packages);
        }

        // Use batch processing for better performance with large package sets
        if (null !== $this->performanceService && count($packages) > 10) {
            return $this->enrichPackagesInBatches($packages);
        }

        // Fallback to sequential processing
        return $this->enrichPackagesSequentially($packages);
    }

    /**
     * Enrich packages in offline mode (cache-only).
     *
     * @param array<Package> $packages
     *
     * @return array<Package>
     */
    private function enrichPackagesOfflineMode(array $packages): array
    {
        if (null === $this->cacheService) {
            // No cache service available, return packages unchanged
            return $packages;
        }

        $enrichedPackages = [];

        foreach ($packages as $package) {
            // Skip cache lookup for dev versions as they won't have cached release dates
            if ($this->isDevVersion($package->version)) {
                $enrichedPackages[] = $package;
                continue;
            }

            $cachedData = $this->cacheService->getPackageInfo($package->name, $package->version);

            if (null !== $cachedData) {
                $enrichedPackages[] = $this->createPackageFromCacheData($package, $cachedData);
            } else {
                // No cached data available, return original package
                $enrichedPackages[] = $package;
            }
        }

        return $enrichedPackages;
    }

    /**
     * Enrich packages using batch processing for better performance.
     *
     * @param array<Package> $packages
     *
     * @return array<Package>
     */
    private function enrichPackagesInBatches(array $packages): array
    {
        if (null === $this->performanceService) {
            // Fallback to sequential processing if performance service not available
            return $this->enrichPackagesSequentially($packages);
        }

        return $this->performanceService->processInBatches(
            $packages,
            fn (array $batch) => $this->enrichPackagesBatch($batch),
        );
    }

    /**
     * Enrich a batch of packages using parallel API requests.
     *
     * @param array<Package> $packages
     *
     * @return array<Package>
     */
    private function enrichPackagesBatch(array $packages): array
    {
        // Check cache first
        $cachedPackages = [];
        $packagesToFetch = [];

        foreach ($packages as $package) {
            // Skip API calls for dev versions as they don't have release dates on Packagist
            if ($this->isDevVersion($package->version)) {
                $cachedPackages[] = $package; // Add without enrichment
                continue;
            }

            $cachedData = $this->cacheService?->getPackageInfo($package->name, $package->version);

            if (null !== $cachedData) {
                $cachedPackages[] = $this->createPackageFromCacheData($package, $cachedData);
            } else {
                $packagesToFetch[] = $package;
            }
        }

        if (empty($packagesToFetch)) {
            return $cachedPackages;
        }

        // Fetch missing packages using batch API
        $packageNames = array_map(fn ($package) => $package->name, $packagesToFetch);
        $batchResults = $this->client->getMultiplePackageInfo($packageNames);

        $enrichedPackages = $cachedPackages;

        foreach ($packagesToFetch as $package) {
            try {
                $packageData = $batchResults[$package->name] ?? null;

                if (null === $packageData) {
                    // API call failed, keep original package
                    $enrichedPackages[] = $package;
                    continue;
                }

                $enrichedPackage = $this->processPackageData($package, $packageData);
                $enrichedPackages[] = $enrichedPackage;
            } catch (PackageInfoException) {
                // Skip packages that fail to process, keep original
                $enrichedPackages[] = $package;
            }
        }

        return $enrichedPackages;
    }

    /**
     * Enrich packages sequentially (fallback method).
     *
     * @param array<Package> $packages
     *
     * @return array<Package>
     */
    private function enrichPackagesSequentially(array $packages): array
    {
        $enrichedPackages = [];

        foreach ($packages as $package) {
            // Skip API calls for dev versions as they don't have release dates on Packagist
            if ($this->isDevVersion($package->version)) {
                $enrichedPackages[] = $package; // Add without enrichment
                continue;
            }

            try {
                $enrichedPackages[] = $this->enrichPackageWithReleaseInfo($package);
            } catch (PackageInfoException) {
                // Skip packages that are not available on Packagist (e.g. local packages, VCS repos)
                // but continue processing other packages
                $enrichedPackages[] = $package; // Add original package without enrichment
            }
        }

        return $enrichedPackages;
    }

    /**
     * Process package data from API response.
     *
     * @param array<string, mixed> $packageData
     *
     * @throws PackageInfoException
     */
    private function processPackageData(Package $package, array $packageData): Package
    {
        if (!isset($packageData['packages'][$package->name])) {
            throw new PackageInfoException("Package data for '{$package->name}' not found in API response");
        }

        $versions = $packageData['packages'][$package->name];

        $installedVersionInfo = $this->findVersionInfo($versions, $package->version);
        $latestVersionInfo = $this->findLatestStableVersion($versions);

        if (null === $installedVersionInfo) {
            throw new PackageInfoException("Version '{$package->version}' not found for package '{$package->name}'");
        }

        $enrichedPackage = $package;

        // Add release date for installed version
        if (isset($installedVersionInfo['time'])) {
            $releaseDate = new DateTimeImmutable($installedVersionInfo['time']);
            $enrichedPackage = $enrichedPackage->withReleaseDate($releaseDate);
        }

        // Add latest version information
        if (null !== $latestVersionInfo && isset($latestVersionInfo['version'], $latestVersionInfo['time'])) {
            $latestReleaseDate = new DateTimeImmutable($latestVersionInfo['time']);
            $enrichedPackage = $enrichedPackage->withLatestVersion(
                $latestVersionInfo['version'],
                $latestReleaseDate,
            );
        }

        // Store in cache if cache service is available
        if (null !== $this->cacheService) {
            $cacheData = $this->createCacheDataFromPackage($enrichedPackage);
            $this->cacheService->storePackageInfo($package->name, $package->version, $cacheData);
        }

        return $enrichedPackage;
    }

    /**
     * Create a package from cached data.
     *
     * @param array<string, mixed> $cachedData
     */
    private function createPackageFromCacheData(Package $package, array $cachedData): Package
    {
        $enrichedPackage = $package;

        if (isset($cachedData['release_date'])) {
            $releaseDate = new DateTimeImmutable($cachedData['release_date']);
            $enrichedPackage = $enrichedPackage->withReleaseDate($releaseDate);
        }

        if (isset($cachedData['latest_version'], $cachedData['latest_release_date'])) {
            $latestReleaseDate = new DateTimeImmutable($cachedData['latest_release_date']);
            $enrichedPackage = $enrichedPackage->withLatestVersion(
                $cachedData['latest_version'],
                $latestReleaseDate,
            );
        }

        return $enrichedPackage;
    }

    /**
     * Find version information for a specific version.
     *
     * @param array<array<string, mixed>> $versions
     *
     * @return array<string, mixed>|null
     */
    private function findVersionInfo(array $versions, string $targetVersion): ?array
    {
        foreach ($versions as $versionInfo) {
            if (isset($versionInfo['version']) && $versionInfo['version'] === $targetVersion) {
                return $versionInfo;
            }
        }

        return null;
    }

    /**
     * Find the latest stable version from available versions.
     *
     * @param array<array<string, mixed>> $versions
     *
     * @return array<string, mixed>|null
     */
    private function findLatestStableVersion(array $versions): ?array
    {
        $stableVersions = [];

        foreach ($versions as $versionInfo) {
            if (!isset($versionInfo['version'])) {
                continue;
            }

            $version = $versionInfo['version'];

            // Skip dev, alpha, beta, RC versions for "latest stable"
            if ($this->isStableVersion($version)) {
                $stableVersions[] = $versionInfo;
            }
        }

        if (empty($stableVersions)) {
            // If no stable versions found, return the first version (which should be latest)
            return $versions[0] ?? null;
        }

        // Sort by version and return the latest
        usort($stableVersions, fn ($a, $b) => version_compare($b['version'], $a['version']));

        return $stableVersions[0];
    }

    /**
     * Check if a version string represents a stable release.
     */
    protected function isStableVersion(string $version): bool
    {
        $version = strtolower($version);

        // Check for common unstable indicators
        $unstablePatterns = [
            'dev',
            'alpha',
            'beta',
            'rc',
            'pre',
            'snapshot',
        ];

        foreach ($unstablePatterns as $pattern) {
            if (str_contains($version, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create package instance from cached data.
     *
     * @param array<string, mixed> $cachedData
     */
    private function createPackageFromCachedData(Package $package, array $cachedData): Package
    {
        $enrichedPackage = $package;

        // Add cached release date
        if (isset($cachedData['release_date'])) {
            $releaseDate = new DateTimeImmutable($cachedData['release_date']);
            $enrichedPackage = $enrichedPackage->withReleaseDate($releaseDate);
        }

        // Add cached latest version info
        if (isset($cachedData['latest_version'], $cachedData['latest_release_date'])) {
            $latestReleaseDate = new DateTimeImmutable($cachedData['latest_release_date']);
            $enrichedPackage = $enrichedPackage->withLatestVersion(
                $cachedData['latest_version'],
                $latestReleaseDate,
            );
        }

        return $enrichedPackage;
    }

    /**
     * Create cache data from enriched package.
     *
     * @return array<string, mixed>
     */
    private function createCacheDataFromPackage(Package $package): array
    {
        $cacheData = [];

        if (null !== $package->releaseDate) {
            $cacheData['release_date'] = $package->releaseDate->format('c');
        }

        if (null !== $package->latestVersion) {
            $cacheData['latest_version'] = $package->latestVersion;
        }

        if (null !== $package->latestReleaseDate) {
            $cacheData['latest_release_date'] = $package->latestReleaseDate->format('c');
        }

        return $cacheData;
    }

    /**
     * Get dependency information from composer.json.
     *
     * @return array{direct: array<string, true>, dev: array<string, true>}
     */
    private function getDependencyInfo(Composer $composer): array
    {
        $composerConfig = $composer->getPackage();
        $directDependencies = [];
        $devDependencies = [];

        // Get production dependencies
        $requires = $composerConfig->getRequires();
        foreach ($requires as $packageName => $constraint) {
            $directDependencies[$packageName] = true;
        }

        // Get development dependencies
        $devRequires = $composerConfig->getDevRequires();
        foreach ($devRequires as $packageName => $constraint) {
            $devDependencies[$packageName] = true;
        }

        return [
            'direct' => $directDependencies,
            'dev' => $devDependencies,
        ];
    }

    /**
     * Check if a package version is a development version.
     *
     * Dev versions (like "dev-main", "1.x-dev", "dev-master") don't have release dates
     * on Packagist, so we can skip API calls for these to improve performance.
     */
    private function isDevVersion(string $version): bool
    {
        return str_starts_with($version, 'dev-')
            || str_ends_with($version, '-dev')
            || str_contains($version, '.x-dev');
    }
}
