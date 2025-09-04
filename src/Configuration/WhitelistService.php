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

namespace KonradMichalik\ComposerDependencyAge\Configuration;

use KonradMichalik\ComposerDependencyAge\Exception\WhitelistException;

/**
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 *
 * @package ComposerDependencyAge
 */
class WhitelistService
{
    /**
     * Load package names from a whitelist file.
     *
     * @return array<string>
     */
    public function loadFromFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new WhitelistException("Whitelist file does not exist: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new WhitelistException("Whitelist file is not readable: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if (false === $content) {
            throw new WhitelistException("Failed to read whitelist file: {$filePath}");
        }

        // Support both JSON and plain text formats
        if ($this->isJsonFile($filePath)) {
            return $this->parseJsonWhitelist($content, $filePath);
        }

        return $this->parseTextWhitelist($content);
    }

    /**
     * Create a whitelist file with default entries.
     */
    public function createDefaultWhitelist(string $filePath): void
    {
        $defaultPackages = Configuration::DEFAULT_IGNORE_PACKAGES;

        $content = [
            'description' => 'Dependency Age Plugin - Package Whitelist',
            'created' => date('c'),
            'packages' => $defaultPackages,
        ];

        $jsonContent = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $jsonContent) {
            throw new WhitelistException('Failed to encode default whitelist as JSON');
        }

        if (false === @file_put_contents($filePath, $jsonContent)) {
            throw new WhitelistException("Failed to write default whitelist file: {$filePath}");
        }
    }

    /**
     * Validate that all packages in whitelist have valid names.
     *
     * @param array<string> $packages
     *
     * @return array<string> List of validation errors
     */
    public function validatePackageNames(array $packages): array
    {
        $errors = [];

        foreach ($packages as $package) {
            if (!is_string($package)) {
                $errors[] = 'Invalid package name (not a string): '.var_export($package, true);
                continue;
            }

            if ('' === trim($package)) {
                $errors[] = 'Empty package name found';
                continue;
            }

            // Basic validation for vendor/package format
            if (!preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $package)) {
                $errors[] = "Invalid package name format: {$package}";
            }
        }

        return $errors;
    }

    private function isJsonFile(string $filePath): bool
    {
        return str_ends_with(strtolower($filePath), '.json');
    }

    /**
     * Parse JSON whitelist format.
     *
     * @return array<string>
     */
    private function parseJsonWhitelist(string $content, string $filePath): array
    {
        $data = json_decode($content, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new WhitelistException("Invalid JSON in whitelist file '{$filePath}': ".json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new WhitelistException('Whitelist JSON must be an array or object');
        }

        // Support both flat array and structured format
        if (isset($data['packages']) && is_array($data['packages'])) {
            $packages = $data['packages'];
        } elseif (array_is_list($data)) {
            $packages = $data;
        } else {
            throw new WhitelistException("Whitelist JSON must contain 'packages' array or be a flat array");
        }

        $errors = $this->validatePackageNames($packages);
        if (!empty($errors)) {
            throw new WhitelistException('Invalid package names in whitelist: '.implode(', ', $errors));
        }

        return array_values(array_unique($packages));
    }

    /**
     * Parse plain text whitelist format.
     *
     * @return array<string>
     */
    private function parseTextWhitelist(string $content): array
    {
        $lines = explode("\n", $content);
        $packages = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            $packages[] = $line;
        }

        $errors = $this->validatePackageNames($packages);
        if (!empty($errors)) {
            throw new WhitelistException('Invalid package names in whitelist: '.implode(', ', $errors));
        }

        return array_values(array_unique($packages));
    }
}
