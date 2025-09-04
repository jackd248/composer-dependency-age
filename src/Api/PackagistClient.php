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

namespace KonradMichalik\ComposerDependencyAge\Api;

use CurlHandle;
use KonradMichalik\ComposerDependencyAge\Exception\ApiException;

/**
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 *
 * @package ComposerDependencyAge
 */
class PackagistClient
{
    private const BASE_URL = 'https://repo.packagist.org';
    private const MAX_REQUESTS_PER_MINUTE = 60;

    private int $requestCount = 0;
    private float $lastRequestTime = 0.0;

    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $maxConcurrentRequests = 5,
        private readonly int $retryAttempts = 3,
        private readonly float $retryDelayMultiplier = 1.5,
        private readonly bool $respectRateLimit = true,
    ) {}

    /**
     * Get package information from Packagist.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function getPackageInfo(string $packageName): array
    {
        $url = sprintf('%s/p2/%s.json', self::BASE_URL, $packageName);

        return $this->makeRequestWithRetry($url, $packageName);
    }

    /**
     * Get multiple packages in parallel using cURL multi-handle.
     *
     * @param array<string> $packageNames
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function getMultiplePackageInfo(array $packageNames): array
    {
        if (empty($packageNames)) {
            return [];
        }

        $results = [];

        // Process packages in batches to respect concurrent request limits
        $batchSize = max(1, $this->maxConcurrentRequests);
        $batches = array_chunk($packageNames, $batchSize);

        foreach ($batches as $batch) {
            $batchResults = $this->processBatch($batch);
            $results = array_merge($results, $batchResults);
        }

        return $results;
    }

    /**
     * Process a batch of packages using parallel cURL requests.
     *
     * @param array<string> $packageNames
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function processBatch(array $packageNames): array
    {
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $results = [];

        // Initialize cURL handles for each package
        foreach ($packageNames as $packageName) {
            $url = sprintf('%s/p2/%s.json', self::BASE_URL, $packageName);
            $curlHandle = $this->createCurlHandle($url);

            curl_multi_add_handle($multiHandle, $curlHandle);
            $curlHandles[$packageName] = $curlHandle;
        }

        // Execute parallel requests
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        // Collect results
        foreach ($curlHandles as $packageName => $curlHandle) {
            try {
                $response = curl_multi_getcontent($curlHandle);
                $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
                $error = curl_error($curlHandle);

                if ($error) {
                    throw new ApiException(sprintf('cURL error for %s: %s', $packageName, $error));
                }

                if (200 !== $httpCode) {
                    throw new ApiException(sprintf('HTTP %d for package %s', $httpCode, $packageName));
                }

                // @phpstan-ignore identical.alwaysFalse (curl_multi_getcontent can return false)
                if (false === $response) {
                    throw new ApiException(sprintf('Empty response for package %s', $packageName));
                }

                // At this point, $response is guaranteed to be a string
                /** @var string $response */
                $data = json_decode($response, true);
                if (null === $data || !is_array($data)) {
                    throw new ApiException(sprintf('Invalid JSON response for package %s', $packageName));
                }

                $results[$packageName] = $data;
            } catch (ApiException) {
                // Skip failed packages, continue with others
                $results[$packageName] = null;
            }

            curl_multi_remove_handle($multiHandle, $curlHandle);
            curl_close($curlHandle);
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    /**
     * Create and configure a cURL handle for a request.
     */
    private function createCurlHandle(string $url): CurlHandle
    {
        $curlHandle = curl_init();

        curl_setopt_array($curlHandle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => [
                'User-Agent: composer-dependency-age/1.0',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return $curlHandle;
    }

    /**
     * Make HTTP request with retry mechanism and exponential backoff.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function makeRequestWithRetry(string $url, string $packageName): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->retryAttempts; ++$attempt) {
            try {
                return $this->makeRequest($url);
            } catch (ApiException $e) {
                $lastException = $e;

                // Don't retry on 404 errors (package not found)
                if (str_contains($e->getMessage(), 'HTTP 404')) {
                    throw $e;
                }

                // Don't retry on final attempt
                if ($attempt === $this->retryAttempts) {
                    break;
                }

                // Calculate delay with exponential backoff
                $delay = (int) (1000000 * ($this->retryDelayMultiplier ** ($attempt - 1))); // microseconds
                usleep($delay);
            }
        }

        throw new ApiException(sprintf('Failed to fetch package info for %s after %d attempts. Last error: %s', $packageName, $this->retryAttempts, $lastException?->getMessage() ?? 'Unknown error'), 0, $lastException);
    }

    /**
     * Make HTTP request to Packagist API with rate limiting.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    protected function makeRequest(string $url): array
    {
        $this->enforceRateLimit();

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'header' => [
                    'User-Agent: composer-dependency-age/1.0',
                    'Accept: application/json',
                ],
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if (false === $response) {
            $error = error_get_last();
            // @phpstan-ignore nullCoalesce.variable (http_response_header is populated by file_get_contents)
            $httpCode = $this->extractHttpCodeFromHeaders($http_response_header ?? null);

            if (404 === $httpCode) {
                throw new ApiException(sprintf('HTTP 404: Package not found at %s', $url));
            }

            throw new ApiException(sprintf('Failed to fetch package info from %s: %s', $url, $error['message'] ?? 'Unknown error'));
        }

        $data = json_decode($response, true);
        if (null === $data) {
            throw new ApiException(sprintf('Invalid JSON response from %s', $url));
        }

        if (!is_array($data)) {
            throw new ApiException(sprintf('Unexpected response format from %s', $url));
        }

        return $data;
    }

    /**
     * Enforce rate limiting to respect API limits.
     */
    private function enforceRateLimit(): void
    {
        if (!$this->respectRateLimit) {
            return;
        }

        $currentTime = microtime(true);

        // Reset counter if a minute has passed
        if ($currentTime - $this->lastRequestTime >= 60.0) {
            $this->requestCount = 0;
            $this->lastRequestTime = $currentTime;
        }

        // If we've hit the rate limit, wait until the next minute
        if ($this->requestCount >= self::MAX_REQUESTS_PER_MINUTE) {
            $sleepTime = 60.0 - ($currentTime - $this->lastRequestTime);
            if ($sleepTime > 0) {
                usleep((int) ($sleepTime * 1000000)); // Convert to microseconds
                $this->requestCount = 0;
                $this->lastRequestTime = microtime(true);
            }
        }

        ++$this->requestCount;
    }

    /**
     * Extract HTTP status code from response headers.
     *
     * @param array<string>|null $headers
     */
    private function extractHttpCodeFromHeaders(?array $headers): ?int
    {
        if (empty($headers)) {
            return null;
        }

        $statusLine = $headers[0];
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Check if a package exists on Packagist.
     *
     * @throws ApiException
     */
    public function packageExists(string $packageName): bool
    {
        try {
            $this->getPackageInfo($packageName);

            return true;
        } catch (ApiException $e) {
            // Check if it's a 404 error (package not found)
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return false;
            }
            // Re-throw other API errors
            throw $e;
        }
    }
}
