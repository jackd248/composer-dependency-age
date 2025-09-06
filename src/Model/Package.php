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
 * Package.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class Package
{
    /**
     * @param array<array<string, mixed>> $releaseHistory
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly bool $isDev = false,
        public readonly bool $isDirect = false,
        public readonly ?DateTimeImmutable $releaseDate = null,
        public readonly ?string $latestVersion = null,
        public readonly ?DateTimeImmutable $latestReleaseDate = null,
        public readonly array $releaseHistory = [],
    ) {}

    public function withReleaseDate(DateTimeImmutable $releaseDate): self
    {
        return new self(
            name: $this->name,
            version: $this->version,
            isDev: $this->isDev,
            isDirect: $this->isDirect,
            releaseDate: $releaseDate,
            latestVersion: $this->latestVersion,
            latestReleaseDate: $this->latestReleaseDate,
            releaseHistory: $this->releaseHistory,
        );
    }

    public function withLatestVersion(string $latestVersion, DateTimeImmutable $latestReleaseDate): self
    {
        return new self(
            name: $this->name,
            version: $this->version,
            isDev: $this->isDev,
            isDirect: $this->isDirect,
            releaseDate: $this->releaseDate,
            latestVersion: $latestVersion,
            latestReleaseDate: $latestReleaseDate,
            releaseHistory: $this->releaseHistory,
        );
    }

    /**
     * @param array<array<string, mixed>> $releaseHistory
     */
    public function withReleaseHistory(array $releaseHistory): self
    {
        return new self(
            name: $this->name,
            version: $this->version,
            isDev: $this->isDev,
            isDirect: $this->isDirect,
            releaseDate: $this->releaseDate,
            latestVersion: $this->latestVersion,
            latestReleaseDate: $this->latestReleaseDate,
            releaseHistory: $releaseHistory,
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

    public function getDependencyType(): string
    {
        if ($this->isDev && !$this->isDirect) {
            return '<fg=magenta>*</fg=magenta><fg=white>~</fg=white>';
        }
        if ($this->isDev) {
            return '<fg=magenta>*</fg=magenta>';
        }
        if ($this->isDirect) {
            return '<fg=cyan>→</fg=cyan>';
        }

        return '<fg=white>~</fg=white>';
    }
}
