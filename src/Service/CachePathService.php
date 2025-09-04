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

use Throwable;

/**
 * CachePathService.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
class CachePathService
{
    private const DEFAULT_CACHE_FILENAME = '.dependency-age.cache';

    /**
     * Get the appropriate cache file path for the current platform.
     */
    public function getCacheFilePath(?string $customPath = null): string
    {
        if (null !== $customPath) {
            return $customPath;
        }

        // Check for project-local cache first
        $projectCachePath = getcwd().'/'.self::DEFAULT_CACHE_FILENAME;
        if (is_writable(dirname($projectCachePath))) {
            return $projectCachePath;
        }

        // Fall back to system cache directory
        return $this->getSystemCachePath();
    }

    /**
     * Get system-specific cache directory path.
     */
    public function getSystemCachePath(): string
    {
        $cacheDir = $this->getSystemCacheDirectory();

        if (!is_dir($cacheDir)) {
            $this->createCacheDirectory($cacheDir);
        }

        return $cacheDir.'/'.self::DEFAULT_CACHE_FILENAME;
    }

    /**
     * Get platform-specific cache directory.
     */
    private function getSystemCacheDirectory(): string
    {
        // Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $localAppData = $_SERVER['LOCALAPPDATA'] ?? $_SERVER['APPDATA'] ?? null;
            if (null !== $localAppData) {
                return $localAppData.'/composer-dependency-age';
            }
        }

        // macOS
        if (PHP_OS_FAMILY === 'Darwin') {
            $home = $_SERVER['HOME'] ?? null;
            if (null !== $home) {
                return $home.'/Library/Caches/composer-dependency-age';
            }
        }

        // Linux/Unix - Follow XDG Base Directory Specification
        $xdgCacheHome = $_SERVER['XDG_CACHE_HOME'] ?? null;
        if (null !== $xdgCacheHome) {
            return $xdgCacheHome.'/composer-dependency-age';
        }

        $home = $_SERVER['HOME'] ?? null;
        if (null !== $home) {
            return $home.'/.cache/composer-dependency-age';
        }

        // Fallback to temp directory
        return sys_get_temp_dir().'/composer-dependency-age';
    }

    /**
     * Create cache directory if it doesn't exist.
     */
    private function createCacheDirectory(string $cacheDir): void
    {
        if (!mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            // If we can't create the directory, fall back to temp
            // This will be handled by the caller
        }
    }

    /**
     * Check if cache directory is writable.
     */
    public function isCacheDirectoryWritable(string $cachePath): bool
    {
        $cacheDir = dirname($cachePath);

        if (!is_dir($cacheDir)) {
            return is_writable(dirname($cacheDir));
        }

        return is_writable($cacheDir);
    }

    /**
     * Get all potential cache file locations for cleanup/discovery.
     *
     * @return array<string>
     */
    public function getAllPotentialCachePaths(): array
    {
        $paths = [];

        // Current working directory
        $paths[] = getcwd().'/'.self::DEFAULT_CACHE_FILENAME;

        // System cache paths
        try {
            $paths[] = $this->getSystemCachePath();
        } catch (Throwable) {
            // Ignore errors for system cache path discovery
        }

        // Temp directory fallback
        $paths[] = sys_get_temp_dir().'/'.self::DEFAULT_CACHE_FILENAME;

        return array_unique($paths);
    }
}
