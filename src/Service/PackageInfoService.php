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

use DateTimeImmutable;
use KonradMichalik\ComposerDependencyAge\Api\PackagistClient;
use KonradMichalik\ComposerDependencyAge\Exception\ApiException;
use KonradMichalik\ComposerDependencyAge\Exception\PackageInfoException;
use KonradMichalik\ComposerDependencyAge\Model\Package;

/**
 * Service for looking up package release information from Packagist.
 */
class PackageInfoService
{
    public function __construct(
        private readonly PackagistClient $client,
    ) {}

    /**
     * Enrich a package with release date information.
     *
     * @throws PackageInfoException
     */
    public function enrichPackageWithReleaseInfo(Package $package): Package
    {
        try {
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

            return $enrichedPackage;
        } catch (ApiException $e) {
            throw new PackageInfoException("Failed to get package info for '{$package->name}': {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Enrich multiple packages with release information.
     *
     * @param array<Package> $packages
     *
     * @return array<Package>
     *
     * @throws PackageInfoException
     */
    public function enrichPackagesWithReleaseInfo(array $packages): array
    {
        $enrichedPackages = [];

        foreach ($packages as $package) {
            try {
                $enrichedPackages[] = $this->enrichPackageWithReleaseInfo($package);
            } catch (PackageInfoException $e) {
                // For batch processing, we might want to skip failed packages
                // and log the error, but continue with others
                throw new PackageInfoException("Batch processing failed at package '{$package->name}': {$e->getMessage()}", previous: $e);
            }
        }

        return $enrichedPackages;
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
}
