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

use Composer\Composer;
use Exception;
use KonradMichalik\ComposerDependencyAge\Exception\ConfigurationException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * ConfigurationLoader.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class ConfigurationLoader
{
    public function __construct(
        private readonly WhitelistService $whitelistService,
    ) {}

    /**
     * Load complete configuration from Composer and command line inputs.
     */
    public function load(Composer $composer, InputInterface $input): Configuration
    {
        // Load base configuration from composer.json
        $baseConfig = $this->loadFromComposer($composer);

        // Apply command line overrides
        $finalConfig = $this->applyCommandLineOverrides($baseConfig, $input);

        // Validate final configuration
        $errors = $finalConfig->validate();
        if (!empty($errors)) {
            throw new ConfigurationException('Configuration validation failed: '.implode(', ', $errors));
        }

        // Load and merge whitelist if specified
        if (null !== $finalConfig->getWhitelistFile()) {
            $finalConfig = $this->mergeWhitelist($finalConfig);
        }

        return $finalConfig;
    }

    /**
     * Load configuration from composer.json extra section.
     */
    private function loadFromComposer(Composer $composer): Configuration
    {
        $package = $composer->getPackage();
        $extra = $package->getExtra();

        return Configuration::fromComposerExtra($extra);
    }

    /**
     * Apply command line argument overrides to configuration.
     */
    private function applyCommandLineOverrides(Configuration $config, InputInterface $input): Configuration
    {
        $overrides = [];

        // Map command line options to configuration keys
        if ($input->hasOption('format') && null !== $input->getOption('format')) {
            $overrides['output_format'] = $input->getOption('format');
        }

        if ($input->hasOption('no-colors') && $input->getOption('no-colors')) {
            $overrides['show_colors'] = false;
        }

        if ($input->hasOption('no-dev') && $input->getOption('no-dev')) {
            $overrides['include_dev'] = false;
        }

        if ($input->hasOption('cache-file') && null !== $input->getOption('cache-file')) {
            $overrides['cache_file'] = $input->getOption('cache-file');
        }

        if ($input->hasOption('api-timeout') && null !== $input->getOption('api-timeout')) {
            $overrides['api_timeout'] = (int) $input->getOption('api-timeout');
        }

        if ($input->hasOption('max-concurrent') && null !== $input->getOption('max-concurrent')) {
            $overrides['max_concurrent_requests'] = (int) $input->getOption('max-concurrent');
        }

        if ($input->hasOption('cache-ttl') && null !== $input->getOption('cache-ttl')) {
            $overrides['cache_ttl'] = (int) $input->getOption('cache-ttl');
        }

        if ($input->hasOption('fail-on-critical') && $input->getOption('fail-on-critical')) {
            $overrides['fail_on_critical'] = true;
        }

        if ($input->hasOption('ignore') && null !== $input->getOption('ignore')) {
            $ignore = $input->getOption('ignore');
            if (is_string($ignore)) {
                $overrides['ignore'] = array_merge(
                    $config->getIgnorePackages(),
                    explode(',', $ignore),
                );
            }
        }

        // Parse threshold overrides if provided
        if ($input->hasOption('thresholds') && null !== $input->getOption('thresholds')) {
            $thresholdString = $input->getOption('thresholds');
            if (is_string($thresholdString)) {
                $overrides['thresholds'] = $this->parseThresholds($thresholdString, $config->getThresholds());
            }
        }

        return $config->withOverrides($overrides);
    }

    /**
     * Parse threshold string from command line.
     *
     * @param array<string, float> $defaultThresholds
     *
     * @return array<string, float>
     */
    private function parseThresholds(string $thresholdString, array $defaultThresholds): array
    {
        $thresholds = $defaultThresholds;

        // Parse format: "current=0.5,medium=1.0,old=2.0"
        $parts = explode(',', $thresholdString);

        foreach ($parts as $part) {
            $part = trim($part);
            if (!str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $key = trim($key);
            $value = trim($value);

            if (in_array($key, ['current', 'medium', 'old'], true) && is_numeric($value)) {
                $thresholds[$key] = (float) $value;
            }
        }

        return $thresholds;
    }

    /**
     * Merge whitelist configuration from external file.
     */
    private function mergeWhitelist(Configuration $config): Configuration
    {
        $whitelistFile = $config->getWhitelistFile();
        if (null === $whitelistFile) {
            return $config;
        }

        try {
            $whitelistPackages = $this->whitelistService->loadFromFile($whitelistFile);

            $mergedIgnore = array_unique(array_merge(
                $config->getIgnorePackages(),
                $whitelistPackages,
            ));

            return $config->withOverrides(['ignore' => $mergedIgnore]);
        } catch (Exception $e) {
            throw new ConfigurationException("Failed to load whitelist file '{$whitelistFile}': ".$e->getMessage());
        }
    }
}
