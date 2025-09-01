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

namespace KonradMichalik\ComposerDependencyAge\Tests\Model;

use DateTimeImmutable;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use PHPUnit\Framework\TestCase;

/**
 * Test the Package model.
 */
final class PackageTest extends TestCase
{
    public function testPackageConstructionWithMinimalData(): void
    {
        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
        );

        $this->assertSame('test/package', $package->name);
        $this->assertSame('1.0.0', $package->version);
        $this->assertFalse($package->isDev);
        $this->assertTrue($package->isProduction());
        $this->assertNotInstanceOf(DateTimeImmutable::class, $package->releaseDate);
        $this->assertNull($package->latestVersion);
        $this->assertNotInstanceOf(DateTimeImmutable::class, $package->latestReleaseDate);
    }

    public function testPackageConstructionWithDevFlag(): void
    {
        $package = new Package(
            name: 'dev/package',
            version: '2.0.0',
            isDev: true,
        );

        $this->assertTrue($package->isDev);
        $this->assertFalse($package->isProduction());
    }

    public function testPackageConstructionWithAllData(): void
    {
        $releaseDate = new DateTimeImmutable('2023-01-15');
        $latestReleaseDate = new DateTimeImmutable('2023-06-20');

        $package = new Package(
            name: 'full/package',
            version: '1.5.0',
            isDev: false,
            releaseDate: $releaseDate,
            latestVersion: '1.8.0',
            latestReleaseDate: $latestReleaseDate,
        );

        $this->assertSame('full/package', $package->name);
        $this->assertSame('1.5.0', $package->version);
        $this->assertFalse($package->isDev);
        $this->assertEquals($releaseDate, $package->releaseDate);
        $this->assertSame('1.8.0', $package->latestVersion);
        $this->assertEquals($latestReleaseDate, $package->latestReleaseDate);
    }

    public function testWithReleaseDateCreatesNewInstance(): void
    {
        $original = new Package('test/package', '1.0.0');
        $releaseDate = new DateTimeImmutable('2023-03-15');

        $updated = $original->withReleaseDate($releaseDate);

        $this->assertNotSame($original, $updated);
        $this->assertNotInstanceOf(DateTimeImmutable::class, $original->releaseDate);
        $this->assertEquals($releaseDate, $updated->releaseDate);
        $this->assertSame($original->name, $updated->name);
        $this->assertSame($original->version, $updated->version);
    }

    public function testWithLatestVersionCreatesNewInstance(): void
    {
        $original = new Package('test/package', '1.0.0');
        $latestReleaseDate = new DateTimeImmutable('2023-06-20');

        $updated = $original->withLatestVersion('1.5.0', $latestReleaseDate);

        $this->assertNotSame($original, $updated);
        $this->assertNull($original->latestVersion);
        $this->assertSame('1.5.0', $updated->latestVersion);
        $this->assertEquals($latestReleaseDate, $updated->latestReleaseDate);
        $this->assertSame($original->name, $updated->name);
        $this->assertSame($original->version, $updated->version);
    }

    public function testGetAgeInDaysWithoutReleaseDate(): void
    {
        $package = new Package('test/package', '1.0.0');

        $age = $package->getAgeInDays();

        $this->assertNull($age);
    }

    public function testGetAgeInDaysWithReleaseDate(): void
    {
        $releaseDate = new DateTimeImmutable('2023-01-01');
        $referenceDate = new DateTimeImmutable('2023-01-31');

        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            releaseDate: $releaseDate,
        );

        $age = $package->getAgeInDays($referenceDate);

        $this->assertSame(30, $age);
    }

    public function testGetAgeInDaysWithCurrentDate(): void
    {
        $yesterday = new DateTimeImmutable('yesterday');

        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            releaseDate: $yesterday,
        );

        $age = $package->getAgeInDays();

        $this->assertGreaterThanOrEqual(1, $age);
        $this->assertLessThanOrEqual(2, $age); // Account for timing differences
    }

    public function testGetAgeInDaysWithFutureReleaseDate(): void
    {
        $referenceDate = new DateTimeImmutable('2023-01-01');
        $futureDate = new DateTimeImmutable('2023-01-11');

        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            releaseDate: $futureDate,
        );

        $age = $package->getAgeInDays($referenceDate);

        $this->assertSame(10, $age);
    }

    public function testGetAgeInDaysWithSameDate(): void
    {
        $date = new DateTimeImmutable('2023-01-01');

        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            releaseDate: $date,
        );

        $age = $package->getAgeInDays($date);

        $this->assertSame(0, $age);
    }

    public function testImmutabilityOfPackage(): void
    {
        $releaseDate = new DateTimeImmutable('2023-01-01');
        $latestReleaseDate = new DateTimeImmutable('2023-06-01');

        $original = new Package(
            name: 'test/package',
            version: '1.0.0',
            isDev: false,
            releaseDate: $releaseDate,
        );

        $withLatest = $original->withLatestVersion('1.5.0', $latestReleaseDate);
        $withNewRelease = $withLatest->withReleaseDate(new DateTimeImmutable('2023-02-01'));

        // Original should remain unchanged
        $this->assertSame('test/package', $original->name);
        $this->assertSame('1.0.0', $original->version);
        $this->assertEquals($releaseDate, $original->releaseDate);
        $this->assertNull($original->latestVersion);

        // Each mutation creates a new instance
        $this->assertNotSame($original, $withLatest);
        $this->assertNotSame($withLatest, $withNewRelease);
        $this->assertNotSame($original, $withNewRelease);
    }
}
