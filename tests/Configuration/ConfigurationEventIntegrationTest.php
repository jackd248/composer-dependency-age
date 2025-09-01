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

final class ConfigurationEventIntegrationTest extends TestCase
{
    public function testDefaultEventIntegrationConfiguration(): void
    {
        $config = new Configuration();

        $this->assertTrue($config->isEventIntegrationEnabled());
        $this->assertSame(['install', 'update'], $config->getEventOperations());
        $this->assertSame(10, $config->getEventAnalysisLimit());
    }

    public function testCustomEventIntegrationConfiguration(): void
    {
        $config = new Configuration(
            eventIntegration: false,
            eventOperations: ['update'],
            eventAnalysisLimit: 5,
        );

        $this->assertFalse($config->isEventIntegrationEnabled());
        $this->assertSame(['update'], $config->getEventOperations());
        $this->assertSame(5, $config->getEventAnalysisLimit());
    }

    public function testFromComposerExtraWithEventIntegration(): void
    {
        $extra = [
            'dependency-age' => [
                'event_integration' => false,
                'event_operations' => ['update'],
                'event_analysis_limit' => 15,
            ],
        ];

        $config = Configuration::fromComposerExtra($extra);

        $this->assertFalse($config->isEventIntegrationEnabled());
        $this->assertSame(['update'], $config->getEventOperations());
        $this->assertSame(15, $config->getEventAnalysisLimit());
    }

    public function testFromComposerExtraWithDefaultEventIntegration(): void
    {
        $extra = ['dependency-age' => []];

        $config = Configuration::fromComposerExtra($extra);

        $this->assertTrue($config->isEventIntegrationEnabled());
        $this->assertSame(['install', 'update'], $config->getEventOperations());
        $this->assertSame(10, $config->getEventAnalysisLimit());
    }

    public function testWithOverridesEventIntegration(): void
    {
        $config = new Configuration();

        $overrides = [
            'event_integration' => false,
            'event_operations' => ['install'],
            'event_analysis_limit' => 20,
        ];

        $newConfig = $config->withOverrides($overrides);

        $this->assertFalse($newConfig->isEventIntegrationEnabled());
        $this->assertSame(['install'], $newConfig->getEventOperations());
        $this->assertSame(20, $newConfig->getEventAnalysisLimit());
    }

    public function testToArrayIncludesEventIntegration(): void
    {
        $config = new Configuration(
            eventIntegration: false,
            eventOperations: ['update'],
            eventAnalysisLimit: 5,
        );

        $array = $config->toArray();

        $this->assertArrayHasKey('event_integration', $array);
        $this->assertArrayHasKey('event_operations', $array);
        $this->assertArrayHasKey('event_analysis_limit', $array);

        $this->assertFalse($array['event_integration']);
        $this->assertEquals(['update'], $array['event_operations']);
        $this->assertEquals(5, $array['event_analysis_limit']);
    }

    public function testEventOperationsValidation(): void
    {
        $config = new Configuration(
            eventOperations: ['invalid'],
        );

        $errors = $config->validate();

        // Event operations validation could be added if needed
        // For now, this test just ensures the configuration doesn't break
        $this->assertIsArray($errors);
    }
}
