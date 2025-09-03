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

use Composer\Composer;
use Composer\Package\RootPackage;
use KonradMichalik\ComposerDependencyAge\Configuration\ConfigurationLoader;
use KonradMichalik\ComposerDependencyAge\Configuration\WhitelistService;
use KonradMichalik\ComposerDependencyAge\Exception\ConfigurationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Test configuration loader functionality.
 */
final class ConfigurationLoaderTest extends TestCase
{
    private ConfigurationLoader $configurationLoader;
    private WhitelistService&MockObject $whitelistService;
    private Composer&MockObject $composer;
    private RootPackage&MockObject $rootPackage;

    protected function setUp(): void
    {
        $this->whitelistService = $this->createMock(WhitelistService::class);
        $this->configurationLoader = new ConfigurationLoader($this->whitelistService);

        $this->composer = $this->createMock(Composer::class);
        $this->rootPackage = $this->createMock(RootPackage::class);

        $this->composer->method('getPackage')
            ->willReturn($this->rootPackage);
    }

    public function testLoadWithEmptyComposerExtra(): void
    {
        $this->rootPackage->method('getExtra')
            ->willReturn([]);

        $input = $this->createInput([]);
        $config = $this->configurationLoader->load($this->composer, $input);

        // Should use default values
        $this->assertSame('cli', $config->getOutputFormat());
        $this->assertTrue($config->shouldShowColors());
        $this->assertTrue($config->shouldIncludeDev());
    }

    public function testLoadWithComposerExtra(): void
    {
        $this->rootPackage->method('getExtra')
            ->willReturn([
                'dependency-age' => [
                    'output_format' => 'json',
                    'include_dev' => true,
                    'ignore' => ['custom/package'],
                ],
            ]);

        $input = $this->createInput([]);
        $config = $this->configurationLoader->load($this->composer, $input);

        $this->assertSame('json', $config->getOutputFormat());
        $this->assertTrue($config->shouldIncludeDev());
        $this->assertContains('custom/package', $config->getIgnorePackages());
    }

    public function testLoadWithCommandLineOverrides(): void
    {
        $this->rootPackage->method('getExtra')
            ->willReturn([
                'dependency-age' => [
                    'output_format' => 'json',
                    'show_colors' => true,
                ],
            ]);

        $input = $this->createInput([
            '--format' => 'cli',
            '--no-colors' => true,
            '--no-dev' => true,
            '--cache-file' => 'custom-cache.json',
            '--api-timeout' => '60',
            '--max-concurrent' => '8',
            '--cache-ttl' => '7200',
            '--fail-on-critical' => true,
            '--ignore' => 'pkg1/test,pkg2/test',
            '--thresholds' => 'current=0.25,medium=0.75,old=1.5',
        ]);

        $config = $this->configurationLoader->load($this->composer, $input);

        // Command line should override composer.json
        $this->assertSame('cli', $config->getOutputFormat());
        $this->assertFalse($config->shouldShowColors());
        $this->assertFalse($config->shouldIncludeDev());
        $this->assertSame('custom-cache.json', $config->getCacheFile());
        $this->assertSame(60, $config->getApiTimeout());
        $this->assertSame(8, $config->getMaxConcurrentRequests());
        $this->assertSame(7200, $config->getCacheTtl());
        $this->assertTrue($config->shouldFailOnCritical());
        $this->assertContains('pkg1/test', $config->getIgnorePackages());
        $this->assertContains('pkg2/test', $config->getIgnorePackages());
        $this->assertEqualsWithDelta(0.25, $config->getThresholds()['current'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.75, $config->getThresholds()['medium'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1.5, $config->getThresholds()['old'], PHP_FLOAT_EPSILON);
    }

    public function testLoadWithPartialThresholds(): void
    {
        $this->rootPackage->method('getExtra')
            ->willReturn([]);

        $input = $this->createInput([
            '--thresholds' => 'medium=1.5',
        ]);

        $config = $this->configurationLoader->load($this->composer, $input);

        // Should keep defaults for green and red, but yellow should be changed
        $this->assertEqualsWithDelta(0.5, $config->getThresholds()['current'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1.5, $config->getThresholds()['medium'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(2.0, $config->getThresholds()['old'], PHP_FLOAT_EPSILON);
    }

    public function testLoadWithInvalidThresholdsString(): void
    {
        $this->rootPackage->method('getExtra')
            ->willReturn([]);

        $input = $this->createInput([
            '--thresholds' => 'invalid-format,yellow,red=not-numeric',
        ]);

        $config = $this->configurationLoader->load($this->composer, $input);

        // Should keep default thresholds when parsing fails
        $this->assertEqualsWithDelta(0.5, $config->getThresholds()['current'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1.0, $config->getThresholds()['medium'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(2.0, $config->getThresholds()['old'], PHP_FLOAT_EPSILON);
    }

    public function testLoadWithConfigurationValidationError(): void
    {
        $this->rootPackage->method('getExtra')
            ->willReturn([
                'dependency-age' => [
                    'output_format' => 'invalid-format',
                ],
            ]);

        $input = $this->createInput([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configuration validation failed');

        $this->configurationLoader->load($this->composer, $input);
    }

    public function testLoadWithWhitelistFile(): void
    {
        $tempDir = sys_get_temp_dir().'/config-loader-test-'.uniqid();
        mkdir($tempDir);
        $whitelistFile = $tempDir.'/whitelist.json';

        // Create the file so it exists for validation
        file_put_contents($whitelistFile, '[]');

        try {
            $this->rootPackage->method('getExtra')
                ->willReturn([
                    'dependency-age' => [
                        'whitelist_file' => $whitelistFile,
                    ],
                ]);

            $this->whitelistService->method('loadFromFile')
                ->with($whitelistFile)
                ->willReturn(['whitelist/package1', 'whitelist/package2']);

            $input = $this->createInput([]);
            $config = $this->configurationLoader->load($this->composer, $input);

            $this->assertContains('whitelist/package1', $config->getIgnorePackages());
            $this->assertContains('whitelist/package2', $config->getIgnorePackages());
        } finally {
            if (file_exists($whitelistFile)) {
                unlink($whitelistFile);
            }
            rmdir($tempDir);
        }
    }

    public function testLoadWithWhitelistFileError(): void
    {
        // The validation will catch the non-existent file before the whitelist service is called
        $this->rootPackage->method('getExtra')
            ->willReturn([
                'dependency-age' => [
                    'whitelist_file' => 'non-existent.json',
                ],
            ]);

        $input = $this->createInput([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configuration validation failed');

        $this->configurationLoader->load($this->composer, $input);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createInput(array $options): ArrayInput
    {
        $definition = new InputDefinition([
            new InputOption('format', null, InputOption::VALUE_REQUIRED),
            new InputOption('no-colors', null, InputOption::VALUE_NONE),
            new InputOption('no-dev', null, InputOption::VALUE_NONE),
            new InputOption('cache-file', null, InputOption::VALUE_REQUIRED),
            new InputOption('api-timeout', null, InputOption::VALUE_REQUIRED),
            new InputOption('max-concurrent', null, InputOption::VALUE_REQUIRED),
            new InputOption('cache-ttl', null, InputOption::VALUE_REQUIRED),
            new InputOption('fail-on-critical', null, InputOption::VALUE_NONE),
            new InputOption('ignore', null, InputOption::VALUE_REQUIRED),
            new InputOption('thresholds', null, InputOption::VALUE_REQUIRED),
        ]);

        return new ArrayInput($options, $definition);
    }
}
