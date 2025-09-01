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

use KonradMichalik\ComposerDependencyAge\Exception\ApiException;

/**
 * Client for interacting with the Packagist API.
 */
class PackagistClient
{
    private const BASE_URL = 'https://repo.packagist.org';

    public function __construct(
        private readonly int $timeout = 30,
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

        return $this->makeRequest($url);
    }

    /**
     * Get multiple packages in parallel (future implementation).
     *
     * @param array<string> $packageNames
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function getMultiplePackageInfo(array $packageNames): array
    {
        $results = [];

        foreach ($packageNames as $packageName) {
            try {
                $results[$packageName] = $this->getPackageInfo($packageName);
            } catch (ApiException) {
                // For now, skip failed packages and continue
                // In a real implementation, we might want to use parallel requests
                $results[$packageName] = null;
            }
        }

        return $results;
    }

    /**
     * Make HTTP request to Packagist API.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    protected function makeRequest(string $url): array
    {
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
            // Check if it's a 404-like error (package not found)
            if (str_contains($e->getMessage(), 'Failed to fetch')) {
                return false;
            }
            // Re-throw other API errors
            throw $e;
        }
    }
}
