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

use DateTimeImmutable;
use InvalidArgumentException;
use Iterator;
use KonradMichalik\ComposerDependencyAge\Exception\ConfigurationException;
use KonradMichalik\ComposerDependencyAge\Exception\ServiceException;
use KonradMichalik\ComposerDependencyAge\Model\Package;
use KonradMichalik\ComposerDependencyAge\Service\AgeCalculationService;
use KonradMichalik\ComposerDependencyAge\Service\RatingService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Test the RatingService class.
 */
final class RatingServiceTest extends TestCase
{
    private RatingService $service;
    private AgeCalculationService $ageService;

    protected function setUp(): void
    {
        $this->ageService = new AgeCalculationService();
        $this->service = new RatingService($this->ageService);
    }

    public function testRatePackageWithNoReleaseDate(): void
    {
        $package = new Package('test/package', '1.0.0');

        $rating = $this->service->ratePackage($package);

        $this->assertEquals('unknown', $rating['category']);
        $this->assertEquals('⚪', $rating['emoji']);
        $this->assertEquals('Unknown', $rating['description']);
        $this->assertNull($rating['age_days']);
        $this->assertEquals('Unknown', $rating['age_formatted']);
    }

    public function testRatePackageWithGreenRating(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $releaseDate = new DateTimeImmutable('2023-04-01'); // ~3 months old
        $package = new Package('test/package', '1.0.0', false, $releaseDate);

        $rating = $this->service->ratePackage($package, [], $referenceDate);

        $this->assertEquals('green', $rating['category']);
        $this->assertEquals('🟢', $rating['emoji']);
        $this->assertEquals('Current', $rating['description']);
        $this->assertEqualsWithDelta(91, $rating['age_days'], 1);
        $this->assertNotNull($rating['age_formatted']);
    }

    public function testRatePackageWithYellowRating(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $releaseDate = new DateTimeImmutable('2022-12-01'); // ~7 months old
        $package = new Package('test/package', '1.0.0', false, $releaseDate);

        $rating = $this->service->ratePackage($package, [], $referenceDate);

        $this->assertEquals('yellow', $rating['category']);
        $this->assertEquals('🟡', $rating['emoji']);
        $this->assertEquals('Outdated', $rating['description']);
        $this->assertGreaterThan(183, $rating['age_days']); // More than 6 months
        $this->assertLessThan(365, $rating['age_days']); // Less than 12 months
    }

    public function testRatePackageWithRedRating(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $releaseDate = new DateTimeImmutable('2022-01-01'); // ~18 months old
        $package = new Package('test/package', '1.0.0', false, $releaseDate);

        $rating = $this->service->ratePackage($package, [], $referenceDate);

        $this->assertEquals('red', $rating['category']);
        $this->assertEquals('🔴', $rating['emoji']);
        $this->assertEquals('Critical', $rating['description']);
        $this->assertGreaterThan(365, $rating['age_days']); // More than 12 months
    }

    public function testRatePackageWithCustomThresholds(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $releaseDate = new DateTimeImmutable('2023-05-01'); // ~2 months old
        $package = new Package('test/package', '1.0.0', false, $releaseDate);

        // Custom thresholds: 30 days green, 90 days yellow
        $customThresholds = ['green' => 30, 'yellow' => 90];
        $rating = $this->service->ratePackage($package, $customThresholds, $referenceDate);

        $this->assertEquals('yellow', $rating['category']);
        $this->assertEquals('🟡', $rating['emoji']);
    }

    public function testRateMultiplePackages(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package('green/package', '1.0.0', false, new DateTimeImmutable('2023-05-01')), // ~2 months
            new Package('yellow/package', '2.0.0', false, new DateTimeImmutable('2022-12-01')), // ~7 months
            new Package('red/package', '3.0.0', false, new DateTimeImmutable('2022-01-01')), // ~18 months
            new Package('unknown/package', '4.0.0'), // No release date
        ];

        $ratings = $this->service->ratePackages($packages, [], $referenceDate);

        $this->assertCount(4, $ratings);
        $this->assertEquals('green', $ratings['green/package']['category']);
        $this->assertEquals('yellow', $ratings['yellow/package']['category']);
        $this->assertEquals('red', $ratings['red/package']['category']);
        $this->assertEquals('unknown', $ratings['unknown/package']['category']);
    }

    public function testGetRatingDistribution(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package('green1/package', '1.0.0', false, new DateTimeImmutable('2023-05-01')),
            new Package('green2/package', '1.0.0', false, new DateTimeImmutable('2023-04-01')),
            new Package('yellow1/package', '2.0.0', false, new DateTimeImmutable('2022-12-01')),
            new Package('red1/package', '3.0.0', false, new DateTimeImmutable('2022-01-01')),
            new Package('unknown/package', '4.0.0'),
        ];

        $distribution = $this->service->getRatingDistribution($packages, [], $referenceDate);

        $this->assertEquals(2, $distribution['green']);
        $this->assertEquals(1, $distribution['yellow']);
        $this->assertEquals(1, $distribution['red']);
        $this->assertEquals(1, $distribution['unknown']);
    }

    public function testGetPackagesByRating(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package('green/package', '1.0.0', false, new DateTimeImmutable('2023-05-01')),
            new Package('yellow/package', '2.0.0', false, new DateTimeImmutable('2022-12-01')),
            new Package('red/package', '3.0.0', false, new DateTimeImmutable('2022-01-01')),
        ];

        $greenPackages = $this->service->getPackagesByRating($packages, 'green', [], $referenceDate);
        $yellowPackages = $this->service->getPackagesByRating($packages, 'yellow', [], $referenceDate);
        $redPackages = $this->service->getPackagesByRating($packages, 'red', [], $referenceDate);

        $this->assertCount(1, $greenPackages);
        $this->assertCount(1, $yellowPackages);
        $this->assertCount(1, $redPackages);
        $this->assertEquals('green/package', $greenPackages[0]->name);
        $this->assertEquals('yellow/package', $yellowPackages[0]->name);
        $this->assertEquals('red/package', $redPackages[0]->name);
    }

    public function testHasCriticalPackages(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');

        // Package collection with no critical packages
        $safePackages = [
            new Package('green/package', '1.0.0', false, new DateTimeImmutable('2023-05-01')),
            new Package('yellow/package', '2.0.0', false, new DateTimeImmutable('2022-12-01')),
        ];

        // Package collection with critical packages
        $criticalPackages = [
            new Package('green/package', '1.0.0', false, new DateTimeImmutable('2023-05-01')),
            new Package('red/package', '3.0.0', false, new DateTimeImmutable('2022-01-01')),
        ];

        $this->assertFalse($this->service->hasCriticalPackages($safePackages, [], $referenceDate));
        $this->assertTrue($this->service->hasCriticalPackages($criticalPackages, [], $referenceDate));
    }

    public function testGetRatingSummary(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $packages = [
            new Package('green1/package', '1.0.0', false, new DateTimeImmutable('2023-05-01')),
            new Package('green2/package', '1.0.0', false, new DateTimeImmutable('2023-04-01')),
            new Package('yellow1/package', '2.0.0', false, new DateTimeImmutable('2022-12-01')),
            new Package('red1/package', '3.0.0', false, new DateTimeImmutable('2022-01-01')),
        ];

        $summary = $this->service->getRatingSummary($packages, [], $referenceDate);

        $this->assertEquals(4, $summary['total_packages']);
        $this->assertEquals(2, $summary['distribution']['green']);
        $this->assertEquals(1, $summary['distribution']['yellow']);
        $this->assertEquals(1, $summary['distribution']['red']);
        $this->assertEquals(0, $summary['distribution']['unknown']);
        $this->assertEqualsWithDelta(50.0, $summary['percentages']['green'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(25.0, $summary['percentages']['yellow'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(25.0, $summary['percentages']['red'], PHP_FLOAT_EPSILON);
        $this->assertTrue($summary['has_critical']);
        $this->assertEqualsWithDelta(62.5, $summary['health_score'], PHP_FLOAT_EPSILON); // (2*1.0 + 1*0.5) / 4 * 100
    }

    public function testGetRatingSummaryWithEmptyPackages(): void
    {
        $summary = $this->service->getRatingSummary([]);

        $this->assertEquals(0, $summary['total_packages']);
        $this->assertEquals(0, $summary['distribution']['green']);
        $this->assertEqualsWithDelta(0.0, $summary['percentages']['green'], PHP_FLOAT_EPSILON);
        $this->assertFalse($summary['has_critical']);
        $this->assertEqualsWithDelta(0.0, $summary['health_score'], PHP_FLOAT_EPSILON);
    }

    public function testGetCategoryEmoji(): void
    {
        $this->assertSame('🟢', $this->service->getCategoryEmoji('green'));
        $this->assertSame('🟡', $this->service->getCategoryEmoji('yellow'));
        $this->assertSame('🔴', $this->service->getCategoryEmoji('red'));
        $this->assertSame('⚪', $this->service->getCategoryEmoji('unknown'));
        $this->assertSame('❓', $this->service->getCategoryEmoji('invalid'));
    }

    public function testGetCategoryDescription(): void
    {
        $this->assertSame('Current', $this->service->getCategoryDescription('green'));
        $this->assertSame('Outdated', $this->service->getCategoryDescription('yellow'));
        $this->assertSame('Critical', $this->service->getCategoryDescription('red'));
        $this->assertSame('Unknown', $this->service->getCategoryDescription('unknown'));
        $this->assertSame('Unknown', $this->service->getCategoryDescription('invalid'));
    }

    public function testGetDetailedRating(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $releaseDate = new DateTimeImmutable('2022-08-01'); // ~11 months old (yellow)
        $latestReleaseDate = new DateTimeImmutable('2023-06-01'); // ~1 month old (green)

        $package = new Package(
            name: 'test/package',
            version: '1.0.0',
            isDev: false,
            releaseDate: $releaseDate,
            latestVersion: '1.5.0',
            latestReleaseDate: $latestReleaseDate,
        );

        $detailed = $this->service->getDetailedRating($package, [], $referenceDate);

        $this->assertEquals('test/package', $detailed['package']);
        $this->assertEquals('1.0.0', $detailed['version']);
        $this->assertFalse($detailed['is_dev']);

        // Check basic rating
        $this->assertArrayHasKey('rating', $detailed);
        $this->assertEquals('yellow', $detailed['rating']['category']);

        // Check latest info
        $this->assertArrayHasKey('latest_info', $detailed);
        $this->assertEquals('1.5.0', $detailed['latest_info']['version']);
        $this->assertEquals('green', $detailed['latest_info']['category']);

        // Check age reduction
        $this->assertArrayHasKey('age_reduction', $detailed);
        $this->assertGreaterThan(0, $detailed['age_reduction']['days']);
        $this->assertNotNull($detailed['age_reduction']['formatted']);
    }

    public function testGetDetailedRatingWithoutLatestInfo(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $package = new Package('test/package', '1.0.0', false, new DateTimeImmutable('2023-05-01'));

        $detailed = $this->service->getDetailedRating($package, [], $referenceDate);

        $this->assertEquals('test/package', $detailed['package']);
        $this->assertArrayHasKey('rating', $detailed);
        $this->assertNull($detailed['latest_info']);
        $this->assertNull($detailed['age_reduction']);
    }

    public function testValidateThresholds(): void
    {
        // Valid thresholds
        $validThresholds = ['green' => 0.5, 'yellow' => 1.0];
        $errors = $this->service->validateThresholds($validThresholds);
        $this->assertEmpty($errors);

        // Missing green threshold
        $missingGreen = ['yellow' => 1.0];
        $errors = $this->service->validateThresholds($missingGreen);
        $this->assertContains('Missing required threshold key: green', $errors);

        // Missing yellow threshold
        $missingYellow = ['green' => 0.5];
        $errors = $this->service->validateThresholds($missingYellow);
        $this->assertContains('Missing required threshold key: yellow', $errors);

        // Negative values
        $negativeThresholds = ['green' => -1, 'yellow' => 1.0];
        $errors = $this->service->validateThresholds($negativeThresholds);
        $this->assertContains("Threshold 'green' must be a positive number, got: integer", $errors);

        // Non-numeric values
        $nonNumericThresholds = ['green' => 'invalid', 'yellow' => 1.0];
        $errors = $this->service->validateThresholds($nonNumericThresholds);
        $this->assertContains("Threshold 'green' must be a positive number, got: string", $errors);

        // Wrong order (green >= yellow)
        $wrongOrderThresholds = ['green' => 2.0, 'yellow' => 1.0];
        $errors = $this->service->validateThresholds($wrongOrderThresholds);
        $this->assertContains('Green threshold (2) must be less than yellow threshold (1)', $errors);
    }

    public function testConvertThresholdsToDays(): void
    {
        $yearThresholds = ['green' => 0.5, 'yellow' => 1.0, 'red' => 2.0];
        $dayThresholds = $this->service->convertThresholdsToDays($yearThresholds);

        $this->assertEquals(183, $dayThresholds['green']); // ~0.5 * 365.25
        $this->assertEquals(365, $dayThresholds['yellow']); // ~1.0 * 365.25
        $this->assertEquals(731, $dayThresholds['red']); // ~2.0 * 365.25
    }

    public function testRatingWithDevPackages(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $devPackage = new Package('test/dev-package', '1.0.0', true, new DateTimeImmutable('2023-05-01'));

        $rating = $this->service->ratePackage($devPackage, [], $referenceDate);

        $this->assertEquals('green', $rating['category']);

        $detailed = $this->service->getDetailedRating($devPackage, [], $referenceDate);
        $this->assertTrue($detailed['is_dev']);
    }

    /**
     * @param array<string, mixed> $thresholds
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('edgeCaseThresholdProvider')]
    public function testEdgeCaseThresholds(int $ageDays, array $thresholds, string $expectedCategory): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');
        $releaseDate = $referenceDate->modify("-{$ageDays} days");
        $package = new Package('test/package', '1.0.0', false, $releaseDate);

        $rating = $this->service->ratePackage($package, $thresholds, $referenceDate);

        $this->assertEquals($expectedCategory, $rating['category']);
    }

    /**
     * @return Iterator<array<int|array<string, mixed>|string>>
     */
    public static function edgeCaseThresholdProvider(): Iterator
    {
        // Standard thresholds (183 days green, 365 days yellow)
        yield [182, [], 'green'];
        // Just under 6 months
        yield [183, [], 'yellow'];
        // Exactly 6 months
        yield [364, [], 'yellow'];
        // Just under 12 months
        yield [365, [], 'red'];
        // Exactly 12 months
        // Custom thresholds
        yield [29, ['green' => 30, 'yellow' => 90], 'green'];
        yield [30, ['green' => 30, 'yellow' => 90], 'yellow'];
        yield [89, ['green' => 30, 'yellow' => 90], 'yellow'];
        yield [90, ['green' => 30, 'yellow' => 90], 'red'];
    }

    public function testHealthScoreCalculation(): void
    {
        $referenceDate = new DateTimeImmutable('2023-07-01');

        // All green packages = 100% health score
        $allGreenPackages = [
            new Package('green1/package', '1.0.0', false, new DateTimeImmutable('2023-05-01')),
            new Package('green2/package', '1.0.0', false, new DateTimeImmutable('2023-04-01')),
        ];

        $summary = $this->service->getRatingSummary($allGreenPackages, [], $referenceDate);
        $this->assertEqualsWithDelta(100.0, $summary['health_score'], PHP_FLOAT_EPSILON);

        // All red packages = 0% health score
        $allRedPackages = [
            new Package('red1/package', '1.0.0', false, new DateTimeImmutable('2022-01-01')),
            new Package('red2/package', '1.0.0', false, new DateTimeImmutable('2021-01-01')),
        ];

        $summary = $this->service->getRatingSummary($allRedPackages, [], $referenceDate);
        $this->assertEqualsWithDelta(0.0, $summary['health_score'], PHP_FLOAT_EPSILON);

        // All yellow packages = 50% health score
        $allYellowPackages = [
            new Package('yellow1/package', '1.0.0', false, new DateTimeImmutable('2022-12-01')),
            new Package('yellow2/package', '1.0.0', false, new DateTimeImmutable('2022-11-01')),
        ];

        $summary = $this->service->getRatingSummary($allYellowPackages, [], $referenceDate);
        $this->assertEqualsWithDelta(50.0, $summary['health_score'], PHP_FLOAT_EPSILON);
    }

    // Error Handling Tests

    public function testRatePackageWithInvalidThresholdsThrowsException(): void
    {
        $package = new Package('test/package', '1.0.0', false, new DateTimeImmutable('2023-05-01'));
        $invalidThresholds = ['green' => 2.0, 'yellow' => 1.0]; // Wrong order

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Invalid threshold configuration');

        $this->service->ratePackage($package, $invalidThresholds);
    }

    public function testRatePackageWithMissingThresholdKeysThrowsException(): void
    {
        $package = new Package('test/package', '1.0.0', false, new DateTimeImmutable('2023-05-01'));
        $invalidThresholds = ['green' => 0.5]; // Missing yellow

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Missing required threshold key: yellow');

        $this->service->ratePackage($package, $invalidThresholds);
    }

    public function testRatePackageWithNegativeThresholdsThrowsException(): void
    {
        $package = new Package('test/package', '1.0.0', false, new DateTimeImmutable('2023-05-01'));
        $invalidThresholds = ['green' => -1, 'yellow' => 1.0];

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must be a positive number');

        $this->service->ratePackage($package, $invalidThresholds);
    }

    public function testRatePackageWithNonNumericThresholdsThrowsException(): void
    {
        $package = new Package('test/package', '1.0.0', false, new DateTimeImmutable('2023-05-01'));
        $invalidThresholds = ['green' => 'invalid', 'yellow' => 1.0];

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must be a positive number');

        $this->service->ratePackage($package, $invalidThresholds);
    }

    public function testRatePackagesWithInvalidPackageThrowsException(): void
    {
        $packages = [
            new Package('valid/package', '1.0.0'),
            'invalid-package', // Not a Package instance
        ];

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Invalid package at index 1: expected Package instance, got string');

        $this->service->ratePackages($packages);
    }

    public function testRatePackagesWithNonArrayThrowsException(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Packages must be an array');

        // @phpstan-ignore-next-line - Intentionally passing invalid type for testing
        $this->service->ratePackages('not-an-array');
    }

    public function testRatePackageWrapsUnexpectedExceptions(): void
    {
        // Create a mock AgeCalculationService that throws an unexpected exception
        $mockAgeService = $this->createMock(AgeCalculationService::class);
        $mockAgeService->method('calculateAgeInDays')
            ->willThrowException(new RuntimeException('Unexpected error'));

        $service = new RatingService($mockAgeService);
        $package = new Package('test/package', '1.0.0', false, new DateTimeImmutable('2023-05-01'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Failed to rate package "test/package": Unexpected error');

        $service->ratePackage($package);
    }

    public function testGetRatingDistributionWithInvalidPackagesThrowsException(): void
    {
        $packages = [
            new Package('valid/package', '1.0.0'),
            null, // Invalid package
        ];

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Invalid package at index 1: expected Package instance, got NULL');

        $this->service->getRatingDistribution($packages);
    }

    public function testGetPackagesByRatingWithInvalidPackagesThrowsException(): void
    {
        $packages = [
            new Package('valid/package', '1.0.0'),
            (object) ['name' => 'invalid'], // Invalid package type
        ];

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Invalid package at index 1: expected Package instance, got object');

        $this->service->getPackagesByRating($packages, 'green');
    }

    public function testHasCriticalPackagesWithInvalidPackagesThrowsException(): void
    {
        $packages = [
            new Package('valid/package', '1.0.0'),
            42, // Invalid package type
        ];

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Invalid package at index 1: expected Package instance, got integer');

        $this->service->hasCriticalPackages($packages);
    }

    public function testGetRatingSummaryWithInvalidPackagesThrowsException(): void
    {
        $packages = [
            new Package('valid/package', '1.0.0'),
            false, // Invalid package type
        ];

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Invalid package at index 1: expected Package instance, got boolean');

        $this->service->getRatingSummary($packages);
    }

    public function testGetDetailedRatingWithInvalidThresholdsThrowsException(): void
    {
        $package = new Package('test/package', '1.0.0', false, new DateTimeImmutable('2023-05-01'));
        $invalidThresholds = ['green' => 2.0, 'yellow' => 1.0]; // Wrong order

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Invalid threshold configuration');

        $this->service->getDetailedRating($package, $invalidThresholds);
    }

    public function testConvertThresholdsToDaysWithValidInput(): void
    {
        $result = $this->service->convertThresholdsToDays(['green' => 0.5, 'yellow' => 1.0]);

        $this->assertArrayHasKey('green', $result);
        $this->assertArrayHasKey('yellow', $result);
        $this->assertIsInt($result['green']);
        $this->assertIsInt($result['yellow']);
    }

    public function testErrorHandlingPreservesExceptionChain(): void
    {
        $originalException = new InvalidArgumentException('Original error');

        // Create a mock that throws the original exception
        $mockAgeService = $this->createMock(AgeCalculationService::class);
        $mockAgeService->method('calculateAgeInDays')
            ->willThrowException($originalException);

        $service = new RatingService($mockAgeService);
        $package = new Package('test/package', '1.0.0', false, new DateTimeImmutable('2023-05-01'));

        try {
            $service->ratePackage($package);
            $this->fail('Expected ServiceException to be thrown');
        } catch (ServiceException $e) {
            $this->assertSame($originalException, $e->getPrevious());
        }
    }

    /**
     * @param array<mixed> $invalidPackages
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPackageListProvider')]
    public function testBulkOperationsWithInvalidPackageTypes(array $invalidPackages, string $expectedMessage): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->service->ratePackages($invalidPackages);
    }

    /**
     * @return Iterator<array<array<mixed>|string>>
     */
    public static function invalidPackageListProvider(): Iterator
    {
        yield 'array with null' => [
            [new Package('test', '1.0.0'), null],
            'Invalid package at index 1: expected Package instance, got NULL',
        ];
        yield 'array with string' => [
            [new Package('test', '1.0.0'), 'invalid'],
            'Invalid package at index 1: expected Package instance, got string',
        ];
        yield 'array with integer' => [
            [new Package('test', '1.0.0'), 42],
            'Invalid package at index 1: expected Package instance, got integer',
        ];
        yield 'array with object' => [
            [new Package('test', '1.0.0'), new stdClass()],
            'Invalid package at index 1: expected Package instance, got object',
        ];
    }

    public function testServiceExceptionContainsPackageNameInMessage(): void
    {
        $mockAgeService = $this->createMock(AgeCalculationService::class);
        $mockAgeService->method('calculateAgeInDays')
            ->willThrowException(new RuntimeException('Mock error'));

        $service = new RatingService($mockAgeService);
        $package = new Package('specific/package-name', '1.0.0', false, new DateTimeImmutable('2023-05-01'));

        try {
            $service->ratePackage($package);
            $this->fail('Expected ServiceException to be thrown');
        } catch (ServiceException $e) {
            $this->assertStringContainsString('specific/package-name', $e->getMessage());
        }
    }
}
