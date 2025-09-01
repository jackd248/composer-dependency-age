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

namespace KonradMichalik\ComposerDependencyAge\Model;

use DateTimeImmutable;

/**
 * Represents a Composer package with its metadata.
 */
final readonly class Package
{
    public function __construct(
        public string $name,
        public string $version,
        public bool $isDev = false,
        public ?DateTimeImmutable $releaseDate = null,
        public ?string $latestVersion = null,
        public ?DateTimeImmutable $latestReleaseDate = null,
    ) {}

    public function withReleaseDate(DateTimeImmutable $releaseDate): self
    {
        return new self(
            name: $this->name,
            version: $this->version,
            isDev: $this->isDev,
            releaseDate: $releaseDate,
            latestVersion: $this->latestVersion,
            latestReleaseDate: $this->latestReleaseDate,
        );
    }

    public function withLatestVersion(string $latestVersion, DateTimeImmutable $latestReleaseDate): self
    {
        return new self(
            name: $this->name,
            version: $this->version,
            isDev: $this->isDev,
            releaseDate: $this->releaseDate,
            latestVersion: $latestVersion,
            latestReleaseDate: $latestReleaseDate,
        );
    }

    public function getAgeInDays(?DateTimeImmutable $referenceDate = null): ?int
    {
        if (null === $this->releaseDate) {
            return null;
        }

        $reference = $referenceDate ?? new DateTimeImmutable();
        $diff = $reference->diff($this->releaseDate);

        return false === $diff->days ? null : $diff->days;
    }

    public function isProduction(): bool
    {
        return !$this->isDev;
    }
}
