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

/**
 * Configuration management for the dependency age plugin.
 */
final class Configuration
{
    public const DEFAULT_IGNORE_PACKAGES = [
        'psr/log',
        'psr/container',
        'psr/http-message',
        'psr/http-factory',
        'psr/cache',
        'psr/simple-cache',
        'psr/event-dispatcher',
        'psr/http-client',
        'psr/http-server-handler',
        'psr/http-server-middleware',
        'psr/link',
    ];

    private const DEFAULT_THRESHOLDS = [
        'current' => 0.5,  // 6 months
        'medium' => 1.0,   // 12 months
        'old' => 2.0,      // 24 months
    ];

    /**
     * @param array<string>        $ignorePackages
     * @param array<string, float> $thresholds
     * @param array<string>        $eventOperations
     */
    public function __construct(
        private readonly array $ignorePackages = self::DEFAULT_IGNORE_PACKAGES,
        private array $thresholds = self::DEFAULT_THRESHOLDS,
        private readonly string $cacheFile = '.dependency-age.cache',
        private readonly bool $includeDev = true,
        private readonly string $outputFormat = 'cli',
        private readonly bool $showColors = true,
        private readonly int $apiTimeout = 30,
        private readonly int $maxConcurrentRequests = 5,
        private readonly int $cacheTtl = 86400,
        private readonly bool $securityChecks = false,
        private readonly bool $failOnCritical = false,
        private readonly ?string $whitelistFile = null,
        private readonly bool $eventIntegration = true,
        private readonly array $eventOperations = ['install', 'update'],
        private readonly int $eventAnalysisLimit = 10,
    ) {}

    /**
     * Create configuration from composer.json extra section.
     *
     * @param array<string, mixed> $extra
     */
    public static function fromComposerExtra(array $extra): self
    {
        $config = $extra['dependency-age'] ?? [];

        return new self(
            ignorePackages: array_merge(
                self::DEFAULT_IGNORE_PACKAGES,
                $config['ignore'] ?? [],
            ),
            thresholds: array_merge(
                self::DEFAULT_THRESHOLDS,
                $config['thresholds'] ?? [],
            ),
            cacheFile: $config['cache_file'] ?? '.dependency-age.cache',
            includeDev: $config['include_dev'] ?? true,
            outputFormat: $config['output_format'] ?? 'cli',
            showColors: $config['show_colors'] ?? true,
            apiTimeout: $config['api_timeout'] ?? 30,
            maxConcurrentRequests: $config['max_concurrent_requests'] ?? 5,
            cacheTtl: $config['cache_ttl'] ?? 86400,
            securityChecks: $config['security_checks'] ?? false,
            failOnCritical: $config['fail_on_critical'] ?? false,
            whitelistFile: $config['whitelist_file'] ?? null,
            eventIntegration: $config['event_integration'] ?? true,
            eventOperations: $config['event_operations'] ?? ['install', 'update'],
            eventAnalysisLimit: $config['event_analysis_limit'] ?? 10,
        );
    }

    /**
     * Create configuration with command line overrides.
     *
     * @param array<string, mixed> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        return new self(
            ignorePackages: $overrides['ignore'] ?? $this->ignorePackages,
            thresholds: $overrides['thresholds'] ?? $this->thresholds,
            cacheFile: $overrides['cache_file'] ?? $this->cacheFile,
            includeDev: $overrides['include_dev'] ?? $this->includeDev,
            outputFormat: $overrides['output_format'] ?? $this->outputFormat,
            showColors: $overrides['show_colors'] ?? $this->showColors,
            apiTimeout: $overrides['api_timeout'] ?? $this->apiTimeout,
            maxConcurrentRequests: $overrides['max_concurrent_requests'] ?? $this->maxConcurrentRequests,
            cacheTtl: $overrides['cache_ttl'] ?? $this->cacheTtl,
            securityChecks: $overrides['security_checks'] ?? $this->securityChecks,
            failOnCritical: $overrides['fail_on_critical'] ?? $this->failOnCritical,
            whitelistFile: $overrides['whitelist_file'] ?? $this->whitelistFile,
            eventIntegration: $overrides['event_integration'] ?? $this->eventIntegration,
            eventOperations: $overrides['event_operations'] ?? $this->eventOperations,
            eventAnalysisLimit: $overrides['event_analysis_limit'] ?? $this->eventAnalysisLimit,
        );
    }

    /**
     * @return array<string>
     */
    public function getIgnorePackages(): array
    {
        return $this->ignorePackages;
    }

    /**
     * @return array<string, float>
     */
    public function getThresholds(): array
    {
        return $this->thresholds;
    }

    public function getCacheFile(): string
    {
        return $this->cacheFile;
    }

    public function shouldIncludeDev(): bool
    {
        return $this->includeDev;
    }

    public function getOutputFormat(): string
    {
        return $this->outputFormat;
    }

    public function shouldShowColors(): bool
    {
        return $this->showColors;
    }

    public function getApiTimeout(): int
    {
        return $this->apiTimeout;
    }

    public function getMaxConcurrentRequests(): int
    {
        return $this->maxConcurrentRequests;
    }

    public function getCacheTtl(): int
    {
        return $this->cacheTtl;
    }

    public function shouldPerformSecurityChecks(): bool
    {
        return $this->securityChecks;
    }

    public function shouldFailOnCritical(): bool
    {
        return $this->failOnCritical;
    }

    public function getWhitelistFile(): ?string
    {
        return $this->whitelistFile;
    }

    public function isEventIntegrationEnabled(): bool
    {
        return $this->eventIntegration;
    }

    /**
     * @return array<string>
     */
    public function getEventOperations(): array
    {
        return $this->eventOperations;
    }

    public function getEventAnalysisLimit(): int
    {
        return $this->eventAnalysisLimit;
    }

    public function isPackageIgnored(string $packageName): bool
    {
        return in_array($packageName, $this->ignorePackages, true);
    }

    /**
     * Validate configuration values.
     *
     * @return array<string> List of validation errors
     */
    public function validate(): array
    {
        $errors = [];

        // Validate thresholds
        foreach ($this->thresholds as $key => $value) {
            if (!in_array($key, ['current', 'medium', 'old'], true)) {
                $errors[] = "Invalid threshold key: {$key}";
            }
            if (!is_numeric($value) || $value < 0) {
                $errors[] = "Threshold {$key} must be a positive number, got: {$value}";
            }
        }

        // Validate threshold order
        if (isset($this->thresholds['current'], $this->thresholds['medium'], $this->thresholds['old'])) {
            if ($this->thresholds['current'] >= $this->thresholds['medium']) {
                $errors[] = 'Current threshold must be less than medium threshold';
            }
            if ($this->thresholds['medium'] >= $this->thresholds['old']) {
                $errors[] = 'Medium threshold must be less than old threshold';
            }
        }

        // Validate output format
        if (!in_array($this->outputFormat, ['cli', 'json', 'github'], true)) {
            $errors[] = "Invalid output format: {$this->outputFormat}";
        }

        // Validate API timeout
        if ($this->apiTimeout <= 0) {
            $errors[] = "API timeout must be positive, got: {$this->apiTimeout}";
        }

        // Validate concurrent requests
        if ($this->maxConcurrentRequests <= 0 || $this->maxConcurrentRequests > 20) {
            $errors[] = "Max concurrent requests must be between 1 and 20, got: {$this->maxConcurrentRequests}";
        }

        // Validate cache TTL
        if ($this->cacheTtl <= 0) {
            $errors[] = "Cache TTL must be positive, got: {$this->cacheTtl}";
        }

        // Validate whitelist file exists if specified
        if (null !== $this->whitelistFile && !file_exists($this->whitelistFile)) {
            $errors[] = "Whitelist file does not exist: {$this->whitelistFile}";
        }

        return $errors;
    }

    /**
     * Convert configuration to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ignore' => $this->ignorePackages,
            'thresholds' => $this->thresholds,
            'cache_file' => $this->cacheFile,
            'include_dev' => $this->includeDev,
            'output_format' => $this->outputFormat,
            'show_colors' => $this->showColors,
            'api_timeout' => $this->apiTimeout,
            'max_concurrent_requests' => $this->maxConcurrentRequests,
            'cache_ttl' => $this->cacheTtl,
            'security_checks' => $this->securityChecks,
            'fail_on_critical' => $this->failOnCritical,
            'whitelist_file' => $this->whitelistFile,
            'event_integration' => $this->eventIntegration,
            'event_operations' => $this->eventOperations,
            'event_analysis_limit' => $this->eventAnalysisLimit,
        ];
    }
}
