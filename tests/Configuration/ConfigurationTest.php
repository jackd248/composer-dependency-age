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

namespace KonradMichalik\ComposerDependencyAge\Tests\Configuration;

use KonradMichalik\ComposerDependencyAge\Configuration\Configuration;
use PHPUnit\Framework\TestCase;

/**
 * ConfigurationTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = new Configuration();

        $this->assertContains('psr/log', $config->getIgnorePackages());
        $this->assertEqualsWithDelta(0.5, $config->getThresholds()['current'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1.0, $config->getThresholds()['medium'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(2.0, $config->getThresholds()['old'], PHP_FLOAT_EPSILON);
        $this->assertSame('.dependency-age.cache', $config->getCacheFile());
        $this->assertTrue($config->shouldIncludeDev());
        $this->assertSame('cli', $config->getOutputFormat());
        $this->assertTrue($config->shouldShowColors());
        $this->assertSame(30, $config->getApiTimeout());
        $this->assertSame(5, $config->getMaxConcurrentRequests());
        $this->assertSame(86400, $config->getCacheTtl());
        $this->assertFalse($config->shouldPerformSecurityChecks());
        $this->assertFalse($config->shouldFailOnCritical());
        $this->assertNull($config->getWhitelistFile());
    }

    public function testCustomConfiguration(): void
    {
        $config = new Configuration(
            ignorePackages: ['custom/package'],
            thresholds: ['current' => 0.25, 'medium' => 0.5, 'old' => 1.0],
            cacheFile: 'custom-cache.json',
            includeDev: true,
            outputFormat: 'json',
            showColors: false,
            apiTimeout: 60,
            maxConcurrentRequests: 10,
            cacheTtl: 3600,
            securityChecks: true,
            failOnCritical: true,
            whitelistFile: 'whitelist.json',
        );

        $this->assertSame(['custom/package'], $config->getIgnorePackages());
        $this->assertSame(['current' => 0.25, 'medium' => 0.5, 'old' => 1.0], $config->getThresholds());
        $this->assertSame('custom-cache.json', $config->getCacheFile());
        $this->assertTrue($config->shouldIncludeDev());
        $this->assertSame('json', $config->getOutputFormat());
        $this->assertFalse($config->shouldShowColors());
        $this->assertSame(60, $config->getApiTimeout());
        $this->assertSame(10, $config->getMaxConcurrentRequests());
        $this->assertSame(3600, $config->getCacheTtl());
        $this->assertTrue($config->shouldPerformSecurityChecks());
        $this->assertTrue($config->shouldFailOnCritical());
        $this->assertSame('whitelist.json', $config->getWhitelistFile());
    }

    public function testFromComposerExtra(): void
    {
        $extra = [
            'dependency-age' => [
                'ignore' => ['test/package'],
                'thresholds' => ['current' => 0.3],
                'cache_file' => 'test-cache',
                'include_dev' => true,
                'api_timeout' => 45,
            ],
        ];

        $config = Configuration::fromComposerExtra($extra);

        $this->assertContains('psr/log', $config->getIgnorePackages()); // Default
        $this->assertContains('test/package', $config->getIgnorePackages()); // Added
        $this->assertEqualsWithDelta(0.3, $config->getThresholds()['current'], PHP_FLOAT_EPSILON); // Overridden
        $this->assertEqualsWithDelta(1.0, $config->getThresholds()['medium'], PHP_FLOAT_EPSILON); // Default
        $this->assertSame('test-cache', $config->getCacheFile());
        $this->assertTrue($config->shouldIncludeDev());
        $this->assertSame(45, $config->getApiTimeout());
    }

    public function testFromEmptyComposerExtra(): void
    {
        $config = Configuration::fromComposerExtra([]);

        // Should use all defaults
        $this->assertContains('psr/log', $config->getIgnorePackages());
        $this->assertSame('.dependency-age.cache', $config->getCacheFile());
        $this->assertTrue($config->shouldIncludeDev());
    }

    public function testWithOverrides(): void
    {
        $baseConfig = new Configuration();
        $overriddenConfig = $baseConfig->withOverrides([
            'output_format' => 'json',
            'show_colors' => false,
            'api_timeout' => 120,
        ]);

        // Base config unchanged
        $this->assertSame('cli', $baseConfig->getOutputFormat());
        $this->assertTrue($baseConfig->shouldShowColors());
        $this->assertSame(30, $baseConfig->getApiTimeout());

        // Override config has new values
        $this->assertSame('json', $overriddenConfig->getOutputFormat());
        $this->assertFalse($overriddenConfig->shouldShowColors());
        $this->assertSame(120, $overriddenConfig->getApiTimeout());
    }

    public function testIsPackageIgnored(): void
    {
        $config = new Configuration(ignorePackages: ['vendor/ignored', 'another/package']);

        $this->assertTrue($config->isPackageIgnored('vendor/ignored'));
        $this->assertTrue($config->isPackageIgnored('another/package'));
        $this->assertFalse($config->isPackageIgnored('vendor/not-ignored'));
    }

    public function testValidateSuccess(): void
    {
        $config = new Configuration();
        $errors = $config->validate();

        $this->assertEmpty($errors);
    }

    public function testValidateInvalidThresholds(): void
    {
        $config = new Configuration(thresholds: [
            'invalid' => 0.5,
            'current' => -1.0,
            'medium' => 'not-numeric',
        ]);

        $errors = $config->validate();

        $this->assertContains('Invalid threshold key: invalid', $errors);
        $this->assertContains('Threshold current must be a positive number, got: -1', $errors);
        $this->assertContains('Threshold medium must be a positive number, got: not-numeric', $errors);
    }

    public function testValidateThresholdOrder(): void
    {
        $config = new Configuration(thresholds: [
            'current' => 2.0,
            'medium' => 1.0,
            'old' => 0.5,
        ]);

        $errors = $config->validate();

        $this->assertContains('Current threshold must be less than medium threshold', $errors);
        $this->assertContains('Medium threshold must be less than old threshold', $errors);
    }

    public function testValidateInvalidOutputFormat(): void
    {
        $config = new Configuration(outputFormat: 'invalid');
        $errors = $config->validate();

        $this->assertContains('Invalid output format: invalid', $errors);
    }

    public function testValidateInvalidApiTimeout(): void
    {
        $config = new Configuration(apiTimeout: -10);
        $errors = $config->validate();

        $this->assertContains('API timeout must be positive, got: -10', $errors);
    }

    public function testValidateInvalidMaxConcurrent(): void
    {
        $config = new Configuration(maxConcurrentRequests: 0);
        $errors = $config->validate();

        $this->assertContains('Max concurrent requests must be between 1 and 20, got: 0', $errors);

        $config = new Configuration(maxConcurrentRequests: 25);
        $errors = $config->validate();

        $this->assertContains('Max concurrent requests must be between 1 and 20, got: 25', $errors);
    }

    public function testValidateInvalidCacheTtl(): void
    {
        $config = new Configuration(cacheTtl: -3600);
        $errors = $config->validate();

        $this->assertContains('Cache TTL must be positive, got: -3600', $errors);
    }

    public function testValidateNonExistentWhitelistFile(): void
    {
        $config = new Configuration(whitelistFile: '/non/existent/file.json');
        $errors = $config->validate();

        $this->assertContains('Whitelist file does not exist: /non/existent/file.json', $errors);
    }

    public function testToArray(): void
    {
        $config = new Configuration(
            ignorePackages: ['test/package'],
            outputFormat: 'json',
        );

        $array = $config->toArray();

        $this->assertEquals(['test/package'], $array['ignore']);
        $this->assertEquals('json', $array['output_format']);
        $this->assertArrayHasKey('thresholds', $array);
        $this->assertArrayHasKey('cache_file', $array);
    }
}
