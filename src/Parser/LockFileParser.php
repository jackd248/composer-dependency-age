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

namespace KonradMichalik\ComposerDependencyAge\Parser;

use JsonException;
use KonradMichalik\ComposerDependencyAge\Exception\LockFileException;
use KonradMichalik\ComposerDependencyAge\Model\Package;

/**
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 *
 * @package ComposerDependencyAge
 */
final class LockFileParser
{
    /**
     * @return array<Package>
     *
     * @throws LockFileException
     */
    public function parseFile(string $lockFilePath): array
    {
        if (!file_exists($lockFilePath)) {
            throw new LockFileException("Lock file not found: {$lockFilePath}");
        }

        $content = file_get_contents($lockFilePath);
        if (false === $content) {
            throw new LockFileException("Could not read lock file: {$lockFilePath}");
        }

        return $this->parseContent($content);
    }

    /**
     * @return array<Package>
     *
     * @throws LockFileException
     */
    public function parseContent(string $lockFileContent): array
    {
        try {
            $data = json_decode($lockFileContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LockFileException('Invalid JSON in lock file: '.$e->getMessage(), 0, $e);
        }

        if (null === $data) {
            throw new LockFileException('Invalid JSON in lock file');
        }

        if (!isset($data['packages']) || !is_array($data['packages'])) {
            throw new LockFileException('Invalid lock file format: missing packages array');
        }

        $packages = [];

        // Parse production packages
        foreach ($data['packages'] as $packageData) {
            $packages[] = $this->createPackageFromData($packageData, false);
        }

        // Parse dev packages if requested and available
        if (isset($data['packages-dev']) && is_array($data['packages-dev'])) {
            foreach ($data['packages-dev'] as $packageData) {
                $packages[] = $this->createPackageFromData($packageData, true);
            }
        }

        return $packages;
    }

    /**
     * @return array<Package>
     *
     * @throws LockFileException
     */
    public function parseProductionOnly(string $lockFileContent): array
    {
        try {
            $data = json_decode($lockFileContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LockFileException('Invalid JSON in lock file: '.$e->getMessage(), 0, $e);
        }

        if (null === $data) {
            throw new LockFileException('Invalid JSON in lock file');
        }

        if (!isset($data['packages']) || !is_array($data['packages'])) {
            throw new LockFileException('Invalid lock file format: missing packages array');
        }

        $packages = [];
        foreach ($data['packages'] as $packageData) {
            $packages[] = $this->createPackageFromData($packageData, false);
        }

        return $packages;
    }

    /**
     * @param array<string, mixed> $packageData
     *
     * @throws LockFileException
     */
    private function createPackageFromData(array $packageData, bool $isDev): Package
    {
        if (!isset($packageData['name']) || !is_string($packageData['name'])) {
            throw new LockFileException('Invalid package data: missing name');
        }

        if (!isset($packageData['version']) || !is_string($packageData['version'])) {
            throw new LockFileException("Invalid package data for {$packageData['name']}: missing version");
        }

        return new Package(
            name: $packageData['name'],
            version: $packageData['version'],
            isDev: $isDev,
        );
    }
}
