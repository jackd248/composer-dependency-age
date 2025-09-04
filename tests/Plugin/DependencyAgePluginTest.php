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

namespace KonradMichalik\ComposerDependencyAge\Tests\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Script\ScriptEvents;
use KonradMichalik\ComposerDependencyAge\Plugin\DependencyAgePlugin;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * DependencyAgePluginTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class DependencyAgePluginTest extends TestCase
{
    private DependencyAgePlugin $plugin;
    private Composer&MockObject $composer;
    private IOInterface&MockObject $io;

    protected function setUp(): void
    {
        $this->plugin = new DependencyAgePlugin();
        $this->composer = $this->createMock(Composer::class);
        $this->io = $this->createMock(IOInterface::class);
    }

    public function testActivate(): void
    {
        $this->plugin->activate($this->composer, $this->io);

        $this->assertTrue(true); // Plugin activation doesn't return anything
    }

    public function testDeactivate(): void
    {
        $this->plugin->deactivate($this->composer, $this->io);

        $this->assertTrue(true); // Plugin deactivation doesn't return anything
    }

    public function testUninstall(): void
    {
        $this->plugin->uninstall($this->composer, $this->io);

        $this->assertTrue(true); // Plugin uninstallation doesn't return anything
    }

    public function testGetSubscribedEvents(): void
    {
        $events = DependencyAgePlugin::getSubscribedEvents();

        $this->assertIsArray($events);
        $this->assertArrayHasKey(ScriptEvents::POST_INSTALL_CMD, $events);
        $this->assertArrayHasKey(ScriptEvents::POST_UPDATE_CMD, $events);

        // Check that the events have the expected structure
        $this->assertEquals([['onPostInstall', 0]], $events[ScriptEvents::POST_INSTALL_CMD]);
        $this->assertEquals([['onPostUpdate', 0]], $events[ScriptEvents::POST_UPDATE_CMD]);
    }

    public function testOnPostInstall(): void
    {
        $package = $this->createMock(\Composer\Package\RootPackageInterface::class);
        $package->method('getExtra')->willReturn([]);

        // Mock repository manager to return empty repository
        $localRepository = $this->createMock(\Composer\Repository\InstalledRepositoryInterface::class);
        $localRepository->method('getPackages')->willReturn([]);
        $repositoryManager = $this->createMock(\Composer\Repository\RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn($localRepository);

        $this->composer->method('getPackage')->willReturn($package);
        $this->composer->method('getRepositoryManager')->willReturn($repositoryManager);
        $this->io->expects($this->atLeastOnce())->method('write');

        $this->plugin->activate($this->composer, $this->io);
        $this->plugin->onPostInstall();

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function testOnPostUpdate(): void
    {
        $package = $this->createMock(\Composer\Package\RootPackageInterface::class);
        $package->method('getExtra')->willReturn([]);

        // Mock repository manager to return empty repository
        $localRepository = $this->createMock(\Composer\Repository\InstalledRepositoryInterface::class);
        $localRepository->method('getPackages')->willReturn([]);
        $repositoryManager = $this->createMock(\Composer\Repository\RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn($localRepository);

        $this->composer->method('getPackage')->willReturn($package);
        $this->composer->method('getRepositoryManager')->willReturn($repositoryManager);
        $this->io->expects($this->atLeastOnce())->method('write');

        $this->plugin->activate($this->composer, $this->io);
        $this->plugin->onPostUpdate();

        // No exception should be thrown
        $this->assertTrue(true);
    }
}
