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

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use KonradMichalik\ComposerDependencyAge\Command\DependencyAgeCommand;
use KonradMichalik\ComposerDependencyAge\CommandProvider;
use PHPUnit\Framework\TestCase;

/**
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 *
 * @package ComposerDependencyAge
 */
final class CommandProviderTest extends TestCase
{
    private CommandProvider $commandProvider;

    protected function setUp(): void
    {
        $this->commandProvider = new CommandProvider();
    }

    public function testImplementsCommandProviderCapability(): void
    {
        $this->assertInstanceOf(CommandProviderCapability::class, $this->commandProvider);
    }

    public function testGetCommandsReturnsArray(): void
    {
        $commands = $this->commandProvider->getCommands();

        $this->assertIsArray($commands);
        $this->assertCount(1, $commands);
    }

    public function testGetCommandsReturnsDependencyAgeCommand(): void
    {
        $commands = $this->commandProvider->getCommands();

        $this->assertInstanceOf(DependencyAgeCommand::class, $commands[0]);
    }
}
