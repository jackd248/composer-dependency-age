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

/**
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 *
 * @package ComposerDependencyAge
 */
final class PerformanceOptimizationService
{
    private const MEMORY_LIMIT_WARNING_THRESHOLD = 0.8; // 80% of memory limit
    private const BATCH_SIZE_SMALL = 10;
    private const BATCH_SIZE_MEDIUM = 25;
    private const BATCH_SIZE_LARGE = 50;

    /**
     * Determine optimal batch size based on available memory and package count.
     *
     * @param array<mixed> $packages
     */
    public function getOptimalBatchSize(array $packages): int
    {
        $packageCount = count($packages);
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitInBytes();

        // Check if we're approaching memory limit
        if ($memoryLimit > 0 && $memoryUsage > ($memoryLimit * self::MEMORY_LIMIT_WARNING_THRESHOLD)) {
            return self::BATCH_SIZE_SMALL; // Conservative approach
        }

        // Adjust batch size based on package count
        if ($packageCount < 20) {
            return min($packageCount, self::BATCH_SIZE_SMALL);
        } elseif ($packageCount < 100) {
            return self::BATCH_SIZE_MEDIUM;
        }

        return self::BATCH_SIZE_LARGE;
    }

    /**
     * Check if we should enable progress reporting based on package count.
     *
     * @param array<mixed> $packages
     */
    public function shouldShowProgressBar(array $packages): bool
    {
        return count($packages) >= 50;
    }

    /**
     * Get memory limit in bytes.
     */
    public function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ('-1' === $memoryLimit || false === $memoryLimit) {
            return -1; // Unlimited
        }

        return $this->convertToBytes($memoryLimit);
    }

    /**
     * Get current memory usage information.
     *
     * @return array<string, int|float>
     */
    public function getMemoryUsageInfo(): array
    {
        $currentUsage = memory_get_usage(true);
        $peakUsage = memory_get_peak_usage(true);
        $memoryLimit = $this->getMemoryLimitInBytes();

        $usagePercentage = $memoryLimit > 0 ? ($currentUsage / $memoryLimit) * 100 : 0.0;

        return [
            'current_usage' => $currentUsage,
            'peak_usage' => $peakUsage,
            'memory_limit' => $memoryLimit,
            'usage_percentage' => $usagePercentage,
        ];
    }

    /**
     * Force garbage collection and return freed memory.
     */
    public function performGarbageCollection(): int
    {
        $memoryBefore = memory_get_usage(true);

        // Force garbage collection
        gc_collect_cycles();

        $memoryAfter = memory_get_usage(true);

        return max(0, $memoryBefore - $memoryAfter);
    }

    /**
     * Check if system is likely offline based on basic connectivity test.
     */
    public function isSystemOffline(): bool
    {
        // Use cURL for more reliable connectivity test
        $curlHandle = curl_init();

        curl_setopt_array($curlHandle, [
            CURLOPT_URL => 'https://repo.packagist.org',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_NOBODY => true, // HEAD request
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($curlHandle);
        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $error = curl_error($curlHandle);
        curl_close($curlHandle);

        // Consider offline only if there's a cURL error or clear network failure
        // Allow redirects (3xx) and success codes (2xx) as online
        return false === $result || !empty($error) || (0 !== $httpCode && $httpCode < 200) || $httpCode >= 500;
    }

    /**
     * Convert memory limit string to bytes.
     */
    private function convertToBytes(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $lastChar = strtolower($memoryLimit[-1]);
        $value = (int) $memoryLimit;

        return match ($lastChar) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Stream process large package arrays to avoid memory issues.
     *
     * @param array<mixed> $packages
     * @param callable     $processor Function to process each batch
     *
     * @return array<mixed>
     */
    public function processInBatches(array $packages, callable $processor): array
    {
        $batchSize = $this->getOptimalBatchSize($packages);
        $results = [];

        $validBatchSize = max(1, $batchSize);
        $batches = array_chunk($packages, $validBatchSize);

        foreach ($batches as $batchIndex => $batch) {
            // Process batch
            $batchResults = $processor($batch, $batchIndex);

            if (is_array($batchResults)) {
                $results = array_merge($results, $batchResults);
            }

            // Periodic garbage collection for large datasets
            if (count($batches) > 10 && ($batchIndex + 1) % 5 === 0) {
                $this->performGarbageCollection();
            }
        }

        return $results;
    }

    /**
     * Create a memory-efficient configuration for large projects.
     *
     * @param array<string, mixed> $baseConfig
     *
     * @return array<string, mixed>
     */
    public function optimizeConfigForLargeProjects(array $baseConfig): array
    {
        $optimizedConfig = $baseConfig;

        // Reduce concurrent requests if memory is limited
        $memoryInfo = $this->getMemoryUsageInfo();
        if ($memoryInfo['usage_percentage'] > 70.0) {
            $optimizedConfig['max_concurrent_requests'] = min(3, $baseConfig['max_concurrent_requests'] ?? 5);
        }

        // Enable more aggressive caching
        $optimizedConfig['cache_ttl'] = max($baseConfig['cache_ttl'] ?? 86400, 7 * 86400); // At least 7 days

        // Disable expensive features if needed
        if ($memoryInfo['usage_percentage'] > 80.0) {
            $optimizedConfig['include_dev'] = false;
            $optimizedConfig['security_checks'] = false;
        }

        return $optimizedConfig;
    }
}
