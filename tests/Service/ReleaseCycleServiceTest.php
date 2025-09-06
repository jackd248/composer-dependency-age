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

use ConsoleStyleKit\ConsoleStyleKit;
use DateTimeImmutable;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\ReleaseCycleService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * ReleaseCycleServiceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class ReleaseCycleServiceTest extends TestCase
{
    private ReleaseCycleService $service;
    private AgeCalculationService $ageService;

    protected function setUp(): void
    {
        $this->ageService = new AgeCalculationService();
        $this->service = new ReleaseCycleService($this->ageService);
    }

    public function testAnalyzeReleaseCycleWithNoHistory(): void
    {
        $package = new Package('test/package', '1.0.0');

        $analysis = $this->service->analyzeReleaseCycle($package);

        $this->assertSame('unknown', $analysis['type']);
        $this->assertSame(0, $analysis['rating']);
        $this->assertNull($analysis['frequency_days']);
        $this->assertSame('unknown', $analysis['trend']);
        $this->assertNull($analysis['last_release_age']);
        $this->assertSame('Insufficient release history data', $analysis['pattern_description']);
    }

    public function testAnalyzeReleaseCycleWithSingleRelease(): void
    {
        $releaseHistory = [
            [
                'version' => '1.0.0',
                'date' => new DateTimeImmutable('2023-01-01'),
                'type' => 'major',
            ],
        ];

        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            releaseHistory: $releaseHistory,
        );

        $analysis = $this->service->analyzeReleaseCycle($package);

        $this->assertSame('single_release', $analysis['type']);
        $this->assertSame(1, $analysis['rating']);
        $this->assertNull($analysis['frequency_days']);
        $this->assertSame('unknown', $analysis['trend']);
        $this->assertSame('Only single release found', $analysis['pattern_description']);
    }

    public function testAnalyzeReleaseCycleWithVeryActivePattern(): void
    {
        $referenceDate = new DateTimeImmutable('2023-06-01');
        $releaseHistory = [
            [
                'version' => '3.0.0',
                'date' => new DateTimeImmutable('2023-05-01'),
                'type' => 'major',
            ],
            [
                'version' => '2.1.0',
                'date' => new DateTimeImmutable('2023-03-01'),
                'type' => 'minor',
            ],
            [
                'version' => '2.0.0',
                'date' => new DateTimeImmutable('2023-01-01'),
                'type' => 'major',
            ],
        ];

        $package = new Package(
            name: 'test/package',
            version: '3.0.0',
            releaseHistory: $releaseHistory,
        );

        $analysis = $this->service->analyzeReleaseCycle($package, $referenceDate);

        $this->assertSame('very_active', $analysis['type']);
        $this->assertSame(3, $analysis['rating']); // ≤ 60 days average
        $this->assertIsInt($analysis['frequency_days']);
        $this->assertLessThanOrEqual(60, $analysis['frequency_days']);
        $this->assertContains($analysis['trend'], ['accelerating', 'slowing', 'stable', 'unknown']);
        $this->assertStringContainsString('Very active development', (string) $analysis['pattern_description']);
    }

    public function testAnalyzeReleaseCycleWithActivePattern(): void
    {
        $referenceDate = new DateTimeImmutable('2023-12-01');
        $releaseHistory = [
            [
                'version' => '2.0.0',
                'date' => new DateTimeImmutable('2023-09-01'), // 3 months ago
                'type' => 'major',
            ],
            [
                'version' => '1.5.0',
                'date' => new DateTimeImmutable('2023-06-01'), // 6 months ago (3 months diff)
                'type' => 'minor',
            ],
            [
                'version' => '1.0.0',
                'date' => new DateTimeImmutable('2023-01-01'), // 11 months ago (5 months diff)
                'type' => 'major',
            ],
        ];

        $package = new Package(
            name: 'test/package',
            version: '2.0.0',
            releaseHistory: $releaseHistory,
        );

        $analysis = $this->service->analyzeReleaseCycle($package, $referenceDate);

        $this->assertSame('active', $analysis['type']);
        $this->assertSame(2, $analysis['rating']); // 61-180 days average
        $this->assertIsInt($analysis['frequency_days']);
        $this->assertGreaterThan(60, $analysis['frequency_days']);
        $this->assertLessThanOrEqual(180, $analysis['frequency_days']);
    }

    public function testAnalyzeReleaseCycleWithModeratePattern(): void
    {
        $releaseHistory = [
            [
                'version' => '2.0.0',
                'date' => new DateTimeImmutable('2023-01-01'),
                'type' => 'major',
            ],
            [
                'version' => '1.0.0',
                'date' => new DateTimeImmutable('2022-01-01'), // 365 days diff
                'type' => 'major',
            ],
        ];

        $package = new Package(
            name: 'test/package',
            version: '2.0.0',
            releaseHistory: $releaseHistory,
        );

        $analysis = $this->service->analyzeReleaseCycle($package);

        $this->assertSame('moderate', $analysis['type']);
        $this->assertSame(1, $analysis['rating']); // 181-365 days average
        $this->assertGreaterThan(180, $analysis['frequency_days']);
        $this->assertLessThanOrEqual(365, $analysis['frequency_days']);
    }

    public function testAnalyzeReleaseCycleWithSlowPattern(): void
    {
        $releaseHistory = [
            [
                'version' => '2.0.0',
                'date' => new DateTimeImmutable('2023-01-01'),
                'type' => 'major',
            ],
            [
                'version' => '1.0.0',
                'date' => new DateTimeImmutable('2021-01-01'), // 730 days diff
                'type' => 'major',
            ],
        ];

        $package = new Package(
            name: 'test/package',
            version: '2.0.0',
            releaseHistory: $releaseHistory,
        );

        $analysis = $this->service->analyzeReleaseCycle($package);

        $this->assertSame('slow', $analysis['type']);
        $this->assertSame(0, $analysis['rating']); // > 365 days average
        $this->assertGreaterThan(365, $analysis['frequency_days']);
    }

    public function testDetectAcceleratingTrend(): void
    {
        $releaseHistory = [
            [
                'version' => '4.0.0',
                'date' => new DateTimeImmutable('2023-06-01'),
                'type' => 'major',
            ],
            [
                'version' => '3.0.0',
                'date' => new DateTimeImmutable('2023-05-01'), // 30 days diff
                'type' => 'major',
            ],
            [
                'version' => '2.0.0',
                'date' => new DateTimeImmutable('2023-04-01'), // 30 days diff (recent: avg 30)
                'type' => 'major',
            ],
            [
                'version' => '1.0.0',
                'date' => new DateTimeImmutable('2023-01-01'), // 90 days diff (older: avg 90)
                'type' => 'major',
            ],
        ];

        $package = new Package(
            name: 'test/package',
            version: '4.0.0',
            releaseHistory: $releaseHistory,
        );

        $analysis = $this->service->analyzeReleaseCycle($package);

        $this->assertSame('accelerating', $analysis['trend']);
        $this->assertStringContainsString('accelerating recently', (string) $analysis['pattern_description']);
    }

    public function testFormatCycleRatingWithoutStyle(): void
    {
        $analysis = ['rating' => 3];
        $formatted = $this->service->formatCycleRating($analysis);
        $this->assertSame('●●●', $formatted);

        $analysis = ['rating' => 2];
        $formatted = $this->service->formatCycleRating($analysis);
        $this->assertSame('●●○', $formatted);

        $analysis = ['rating' => 1];
        $formatted = $this->service->formatCycleRating($analysis);
        $this->assertSame('●○○', $formatted);

        $analysis = ['rating' => 0];
        $formatted = $this->service->formatCycleRating($analysis);
        $this->assertSame('○○○', $formatted);
    }

    public function testFormatCycleRatingWithStyle(): void
    {
        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $style = new ConsoleStyleKit($input, $output);

        $analysis = ['rating' => 3];
        $formatted = $this->service->formatCycleRating($analysis, $style);

        // Should contain rating element output (exact format may vary)
        $this->assertIsString($formatted);
        $this->assertNotEmpty($formatted);
    }
}
