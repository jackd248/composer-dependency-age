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
use Composer\Package\RootPackageInterface;
use Exception;
use KonradMichalik\ComposerDependencyAge\Plugin\ComposerEventHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ComposerEventHandlerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class ComposerEventHandlerTest extends TestCase
{
    private ComposerEventHandler $handler;
    private Composer&MockObject $composer;
    private IOInterface&MockObject $io;

    protected function setUp(): void
    {
        $this->composer = $this->createMock(Composer::class);
        $this->io = $this->createMock(IOInterface::class);

        // Mock the package to return empty extra config
        $package = $this->createMock(RootPackageInterface::class);
        $package->method('getExtra')->willReturn([]);
        $this->composer->method('getPackage')->willReturn($package);

        $this->handler = new ComposerEventHandler($this->composer, $this->io);
    }

    public function testHandlePostOperationWithEventIntegrationDisabled(): void
    {
        // Create configuration with event integration disabled
        $config = new \KonradMichalik\ComposerDependencyAge\Configuration\Configuration(
            eventIntegration: false,
        );

        $handler = new ComposerEventHandler($this->composer, $this->io, $config);

        // Should not write anything if event integration is disabled
        $this->io->expects($this->never())->method('write');

        $handler->handlePostOperation('install');
    }

    public function testHandlePostOperationWithSpecificOperations(): void
    {
        // Create configuration that only allows update operations
        $config = new \KonradMichalik\ComposerDependencyAge\Configuration\Configuration(
            eventOperations: ['update'],
        );

        $handler = new ComposerEventHandler($this->composer, $this->io, $config);

        // Should not write anything for install operation
        $this->io->expects($this->never())->method('write');

        $handler->handlePostOperation('install');
    }

    public function testHandlePostOperationWithAllowedOperation(): void
    {
        // Create configuration that allows install operations
        $config = new \KonradMichalik\ComposerDependencyAge\Configuration\Configuration(
            eventOperations: ['install', 'update'],
        );

        // Mock repository manager to return empty repository
        $localRepository = $this->createMock(\Composer\Repository\InstalledRepositoryInterface::class);
        $localRepository->method('getPackages')->willReturn([]);
        $repositoryManager = $this->createMock(\Composer\Repository\RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn($localRepository);
        $this->composer->method('getRepositoryManager')->willReturn($repositoryManager);

        $handler = new ComposerEventHandler($this->composer, $this->io, $config);

        // Should write analysis message for allowed operation
        $this->io->expects($this->atLeastOnce())
            ->method('write');

        $handler->handlePostOperation('install');
    }

    public function testHandlePostOperationWithDefaultConfiguration(): void
    {
        // Use default configuration (event integration enabled)
        $config = new \KonradMichalik\ComposerDependencyAge\Configuration\Configuration();

        // Mock repository manager to return empty repository
        $localRepository = $this->createMock(\Composer\Repository\InstalledRepositoryInterface::class);
        $localRepository->method('getPackages')->willReturn([]);
        $repositoryManager = $this->createMock(\Composer\Repository\RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn($localRepository);
        $this->composer->method('getRepositoryManager')->willReturn($repositoryManager);

        $handler = new ComposerEventHandler($this->composer, $this->io, $config);

        // Should write analysis message with default configuration
        $this->io->expects($this->atLeastOnce())
            ->method('write');

        $handler->handlePostOperation('install');
    }

    public function testHandlePostOperationWithException(): void
    {
        // Use default configuration
        $config = new \KonradMichalik\ComposerDependencyAge\Configuration\Configuration();

        // Mock composer to throw an exception during analysis
        $this->composer->method('getRepositoryManager')
            ->willThrowException(new Exception('Test exception'));

        $handler = new ComposerEventHandler($this->composer, $this->io, $config);

        // Should write error message
        $this->io->expects($this->atLeastOnce())
            ->method('writeError')
            ->with($this->stringContains('Dependency age analysis failed'));

        $handler->handlePostOperation('install');
    }
}
