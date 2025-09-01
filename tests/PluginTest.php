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

namespace KonradMichalik\ComposerDependencyAge\Tests;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use KonradMichalik\ComposerDependencyAge\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Test the main Plugin class.
 */
final class PluginTest extends TestCase
{
    private Plugin $plugin;

    protected function setUp(): void
    {
        $this->plugin = new Plugin();
    }

    public function testPluginImplementsRequiredInterfaces(): void
    {
        $this->assertInstanceOf(\Composer\Plugin\PluginInterface::class, $this->plugin);
        $this->assertInstanceOf(\Composer\Plugin\Capable::class, $this->plugin);
    }

    public function testGetCapabilitiesReturnsCommandProvider(): void
    {
        $capabilities = $this->plugin->getCapabilities();

        $this->assertIsArray($capabilities);
        $this->assertArrayHasKey(CommandProvider::class, $capabilities);
        $this->assertEquals(
            \KonradMichalik\ComposerDependencyAge\CommandProvider::class,
            $capabilities[CommandProvider::class],
        );
    }

    public function testActivateDoesNotThrowException(): void
    {
        $composer = $this->createMock(Composer::class);
        $io = $this->createMock(IOInterface::class);

        $this->expectNotToPerformAssertions();
        $this->plugin->activate($composer, $io);
    }

    public function testDeactivateDoesNotThrowException(): void
    {
        $composer = $this->createMock(Composer::class);
        $io = $this->createMock(IOInterface::class);

        $this->expectNotToPerformAssertions();
        $this->plugin->deactivate($composer, $io);
    }

    public function testUninstallDoesNotThrowException(): void
    {
        $composer = $this->createMock(Composer::class);
        $io = $this->createMock(IOInterface::class);

        $this->expectNotToPerformAssertions();
        $this->plugin->uninstall($composer, $io);
    }
}
