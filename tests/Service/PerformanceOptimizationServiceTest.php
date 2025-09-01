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

namespace KonradMichalik\ComposerDependencyAge\Tests\Service;

use KonradMichalik\ComposerDependencyAge\Service\PerformanceOptimizationService;
use PHPUnit\Framework\TestCase;

final class PerformanceOptimizationServiceTest extends TestCase
{
    private PerformanceOptimizationService $service;

    protected function setUp(): void
    {
        $this->service = new PerformanceOptimizationService();
    }

    public function testGetOptimalBatchSizeWithSmallPackageCount(): void
    {
        $packages = array_fill(0, 5, 'test-package');

        $batchSize = $this->service->getOptimalBatchSize($packages);

        $this->assertIsInt($batchSize);
        $this->assertGreaterThan(0, $batchSize);
        $this->assertLessThanOrEqual(10, $batchSize);
        $this->assertLessThanOrEqual(count($packages), $batchSize);
    }

    public function testGetOptimalBatchSizeWithMediumPackageCount(): void
    {
        $packages = array_fill(0, 50, 'test-package');

        $batchSize = $this->service->getOptimalBatchSize($packages);

        $this->assertIsInt($batchSize);
        $this->assertGreaterThan(10, $batchSize);
        $this->assertLessThanOrEqual(25, $batchSize);
    }

    public function testGetOptimalBatchSizeWithLargePackageCount(): void
    {
        $packages = array_fill(0, 200, 'test-package');

        $batchSize = $this->service->getOptimalBatchSize($packages);

        $this->assertIsInt($batchSize);
        $this->assertGreaterThan(25, $batchSize);
        $this->assertLessThanOrEqual(50, $batchSize);
    }

    public function testShouldShowProgressBarWithSmallPackageCount(): void
    {
        $packages = array_fill(0, 30, 'test-package');

        $shouldShow = $this->service->shouldShowProgressBar($packages);

        $this->assertFalse($shouldShow);
    }

    public function testShouldShowProgressBarWithLargePackageCount(): void
    {
        $packages = array_fill(0, 60, 'test-package');

        $shouldShow = $this->service->shouldShowProgressBar($packages);

        $this->assertTrue($shouldShow);
    }

    public function testGetMemoryLimitInBytes(): void
    {
        $memoryLimit = $this->service->getMemoryLimitInBytes();

        $this->assertIsInt($memoryLimit);
        // Memory limit should be either -1 (unlimited) or a positive number
        $this->assertTrue(-1 === $memoryLimit || $memoryLimit > 0);
    }

    public function testGetMemoryUsageInfo(): void
    {
        $info = $this->service->getMemoryUsageInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('current_usage', $info);
        $this->assertArrayHasKey('peak_usage', $info);
        $this->assertArrayHasKey('memory_limit', $info);
        $this->assertArrayHasKey('usage_percentage', $info);

        $this->assertIsInt($info['current_usage']);
        $this->assertIsInt($info['peak_usage']);
        $this->assertIsInt($info['memory_limit']);
        $this->assertIsFloat($info['usage_percentage']);

        $this->assertGreaterThan(0, $info['current_usage']);
        $this->assertGreaterThanOrEqual($info['current_usage'], $info['peak_usage']);
    }

    public function testPerformGarbageCollection(): void
    {
        // Create some objects to potentially free
        $largeArray = array_fill(0, 1000, str_repeat('x', 1000));

        $freedMemory = $this->service->performGarbageCollection();

        // Freed memory should be non-negative
        $this->assertGreaterThanOrEqual(0, $freedMemory);
        $this->assertIsInt($freedMemory);

        // Clean up
        unset($largeArray);
    }

    public function testIsSystemOffline(): void
    {
        $isOffline = $this->service->isSystemOffline();

        $this->assertIsBool($isOffline);

        // We can't reliably test this without mocking network conditions,
        // but we can ensure it doesn't throw exceptions
    }

    public function testProcessInBatches(): void
    {
        $packages = range(1, 75); // 75 items to ensure multiple batches (should use BATCH_SIZE_LARGE = 50)
        $processedBatches = [];

        $result = $this->service->processInBatches($packages, function (array $batch, int $batchIndex) use (&$processedBatches) {
            $processedBatches[] = [
                'index' => $batchIndex,
                'size' => count($batch),
                'items' => $batch,
            ];

            // Return processed items (multiply by 10 as example)
            return array_map(fn ($item) => $item * 10, $batch);
        });

        // Check that all items were processed
        $this->assertCount(75, $result);
        $this->assertEquals(array_map(fn ($i) => $i * 10, $packages), $result);

        // Check that batching occurred (75 items with batch size 50 should create 2 batches)
        $this->assertGreaterThan(1, count($processedBatches));

        // Check total items across all batches
        $totalProcessed = array_sum(array_column($processedBatches, 'size'));
        $this->assertSame(75, $totalProcessed);
    }

    public function testProcessInBatchesWithSmallDataset(): void
    {
        $packages = [1, 2, 3]; // Small dataset
        $batchCount = 0;

        $result = $this->service->processInBatches($packages, function (array $batch) use (&$batchCount) {
            ++$batchCount;

            return array_map(fn ($item) => $item * 2, $batch);
        });

        $this->assertCount(3, $result);
        $this->assertSame([2, 4, 6], $result);
        $this->assertSame(1, $batchCount); // Should be processed in single batch
    }

    public function testOptimizeConfigForLargeProjects(): void
    {
        $baseConfig = [
            'max_concurrent_requests' => 10,
            'cache_ttl' => 86400,
            'include_dev' => true,
            'security_checks' => true,
        ];

        $optimizedConfig = $this->service->optimizeConfigForLargeProjects($baseConfig);

        $this->assertIsArray($optimizedConfig);
        $this->assertArrayHasKey('max_concurrent_requests', $optimizedConfig);
        $this->assertArrayHasKey('cache_ttl', $optimizedConfig);
        $this->assertArrayHasKey('include_dev', $optimizedConfig);
        $this->assertArrayHasKey('security_checks', $optimizedConfig);

        // Cache TTL should be at least 7 days
        $this->assertGreaterThanOrEqual(7 * 86400, $optimizedConfig['cache_ttl']);
    }

    public function testOptimizeConfigForLargeProjectsWithHighMemoryUsage(): void
    {
        // This test simulates the scenario where memory usage is high
        // In practice, this would depend on actual system conditions

        $baseConfig = [
            'max_concurrent_requests' => 10,
            'cache_ttl' => 3600, // 1 hour
            'include_dev' => true,
            'security_checks' => true,
        ];

        $optimizedConfig = $this->service->optimizeConfigForLargeProjects($baseConfig);

        // Should always increase cache TTL to at least 7 days
        $this->assertGreaterThanOrEqual(7 * 86400, $optimizedConfig['cache_ttl']);

        // Other settings depend on actual memory usage, so we just check they exist
        $this->assertArrayHasKey('max_concurrent_requests', $optimizedConfig);
        $this->assertArrayHasKey('include_dev', $optimizedConfig);
        $this->assertArrayHasKey('security_checks', $optimizedConfig);
    }
}
