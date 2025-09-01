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
use KonradMichalik\ComposerDependencyAge\Exception\CacheException;

/**
 * Service for caching package information to improve performance.
 *
 * Cache file format:
 * {
 *   "version": "1.0",
 *   "created": "2023-07-01T12:00:00+00:00",
 *   "ttl": 86400,
 *   "packages": {
 *     "vendor/package": {
 *       "1.0.0": {
 *         "release_date": "2023-01-01T12:00:00+00:00",
 *         "latest_version": "1.0.1",
 *         "latest_release_date": "2023-02-01T12:00:00+00:00",
 *         "cached_at": "2023-07-01T12:00:00+00:00"
 *       }
 *     }
 *   }
 * }
 */
class CacheService
{
    private const CACHE_VERSION = '1.0';
    private const DEFAULT_TTL = 86400; // 24 hours
    private const MAX_CACHE_SIZE = 10 * 1024 * 1024; // 10MB

    public function __construct(
        private readonly string $cacheFile = '.dependency-age-cache',
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {}

    /**
     * Get cached package information.
     *
     * @return array<string, mixed>|null
     *
     * @throws CacheException
     */
    public function getPackageInfo(string $packageName, string $version): ?array
    {
        $cache = $this->loadCache();

        if (!isset($cache['packages'][$packageName][$version])) {
            return null;
        }

        $packageData = $cache['packages'][$packageName][$version];

        // Check if cached data is still valid
        if ($this->isCacheExpired($packageData['cached_at'])) {
            return null;
        }

        return $packageData;
    }

    /**
     * Store package information in cache.
     *
     * @param array<string, mixed> $packageData
     *
     * @throws CacheException
     */
    public function storePackageInfo(string $packageName, string $version, array $packageData): void
    {
        $cache = $this->loadCache();

        if (!isset($cache['packages'][$packageName])) {
            $cache['packages'][$packageName] = [];
        }

        $cache['packages'][$packageName][$version] = array_merge($packageData, [
            'cached_at' => (new DateTimeImmutable())->format('c'),
        ]);

        $this->saveCache($cache);
    }

    /**
     * Check if cache exists and is valid.
     */
    public function isCacheValid(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        try {
            $cache = $this->loadCache();

            return !$this->isCacheExpired($cache['created']);
        } catch (CacheException) {
            return false;
        }
    }

    /**
     * Clear the entire cache.
     *
     * @throws CacheException
     */
    public function clearCache(): void
    {
        if (file_exists($this->cacheFile)) {
            if (!unlink($this->cacheFile)) {
                throw new CacheException("Failed to delete cache file: {$this->cacheFile}");
            }
        }
    }

    /**
     * Get cache statistics.
     *
     * @return array<string, mixed>
     */
    public function getCacheStats(): array
    {
        if (!file_exists($this->cacheFile)) {
            return [
                'exists' => false,
                'size' => 0,
                'packages' => 0,
                'entries' => 0,
            ];
        }

        $size = filesize($this->cacheFile);
        $cache = $this->loadCache();

        $totalEntries = 0;
        foreach ($cache['packages'] as $packageVersions) {
            $totalEntries += count($packageVersions);
        }

        return [
            'exists' => true,
            'size' => $size ?: 0,
            'packages' => count($cache['packages']),
            'entries' => $totalEntries,
            'created' => $cache['created'],
            'valid' => $this->isCacheValid(),
        ];
    }

    /**
     * Load cache data from file.
     *
     * @return array<string, mixed>
     *
     * @throws CacheException
     */
    private function loadCache(): array
    {
        if (!file_exists($this->cacheFile)) {
            return $this->createEmptyCache();
        }

        $content = file_get_contents($this->cacheFile);
        if (false === $content) {
            throw new CacheException("Failed to read cache file: {$this->cacheFile}");
        }

        // Handle empty file (create empty cache)
        if ('' === trim($content)) {
            return $this->createEmptyCache();
        }

        $cache = json_decode($content, true);
        if (null === $cache) {
            // Invalid JSON - create empty cache instead of throwing
            return $this->createEmptyCache();
        }

        // Validate cache structure
        if (!$this->isValidCacheStructure($cache)) {
            return $this->createEmptyCache();
        }

        return $cache;
    }

    /**
     * Save cache data to file.
     *
     * @param array<string, mixed> $cache
     *
     * @throws CacheException
     */
    private function saveCache(array $cache): void
    {
        // Check cache size limit before saving
        $jsonContent = json_encode($cache, JSON_PRETTY_PRINT);
        if (false === $jsonContent) {
            throw new CacheException('Failed to encode cache data as JSON');
        }

        if (strlen($jsonContent) > self::MAX_CACHE_SIZE) {
            // Clean up old entries and retry
            $cache = $this->cleanupOldEntries($cache);
            $jsonContent = json_encode($cache, JSON_PRETTY_PRINT);
            if (false === $jsonContent) {
                throw new CacheException('Failed to encode cache data as JSON after cleanup');
            }
        }

        if (false === @file_put_contents($this->cacheFile, $jsonContent)) {
            throw new CacheException("Failed to write cache file: {$this->cacheFile}");
        }
    }

    /**
     * Create an empty cache structure.
     *
     * @return array<string, mixed>
     */
    private function createEmptyCache(): array
    {
        return [
            'version' => self::CACHE_VERSION,
            'created' => (new DateTimeImmutable())->format('c'),
            'ttl' => $this->ttl,
            'packages' => [],
        ];
    }

    /**
     * Validate cache structure.
     *
     * @param array<string, mixed> $cache
     */
    private function isValidCacheStructure(array $cache): bool
    {
        $requiredKeys = ['version', 'created', 'ttl', 'packages'];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $cache)) {
                return false;
            }
        }

        // Check version compatibility
        if (self::CACHE_VERSION !== $cache['version']) {
            return false;
        }

        if (!is_array($cache['packages'])) {
            return false;
        }

        return true;
    }

    /**
     * Check if cached data is expired.
     */
    private function isCacheExpired(string $cachedAt): bool
    {
        $cachedTime = new DateTimeImmutable($cachedAt);
        $expiryTime = $cachedTime->modify("+{$this->ttl} seconds");

        return new DateTimeImmutable() > $expiryTime;
    }

    /**
     * Clean up old cache entries to reduce size.
     *
     * @param array<string, mixed> $cache
     *
     * @return array<string, mixed>
     */
    private function cleanupOldEntries(array $cache): array
    {
        $now = new DateTimeImmutable();

        foreach ($cache['packages'] as $packageName => $versions) {
            foreach ($versions as $version => $data) {
                if (isset($data['cached_at']) && $this->isCacheExpired($data['cached_at'])) {
                    unset($cache['packages'][$packageName][$version]);
                }
            }

            // Remove package entry if no versions left
            if (empty($cache['packages'][$packageName])) {
                unset($cache['packages'][$packageName]);
            }
        }

        return $cache;
    }
}
