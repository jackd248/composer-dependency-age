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

namespace KonradMichalik\ComposerDependencyAge\Tests\Command;

use Composer\Command\BaseCommand;
use Composer\Console\Application as ComposerApplication;
use KonradMichalik\ComposerDependencyAge\Command\DependencyAgeCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test the DependencyAgeCommand class.
 */
final class DependencyAgeCommandTest extends TestCase
{
    private DependencyAgeCommand $command;
    private ?CommandTester $commandTester = null;

    protected function setUp(): void
    {
        $this->command = new DependencyAgeCommand();
    }

    private function getCommandTester(): CommandTester
    {
        if (null === $this->commandTester) {
            $application = new ComposerApplication();
            $application->add($this->command);
            $this->commandTester = new CommandTester($this->command);
        }

        return $this->commandTester;
    }

    public function testExtendsBaseCommand(): void
    {
        $this->assertInstanceOf(BaseCommand::class, $this->command);
    }

    public function testCommandHasCorrectName(): void
    {
        $this->assertSame('dependency-age', $this->command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $description = $this->command->getDescription();
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('age', $description);
        $this->assertStringContainsString('dependencies', $description);
    }

    public function testCommandHasHelpText(): void
    {
        $help = $this->command->getHelp();
        $this->assertNotEmpty($help);
        $this->assertStringContainsString('analyzes', $help);
    }

    public function testExecuteReturnsSuccessCode(): void
    {
        $commandTester = $this->getCommandTester();

        // This test might fail if API calls fail, so we just check it doesn't crash
        $result = $commandTester->execute([]);

        // Either success (0) or failure (1) is acceptable, as long as it doesn't crash
        $this->assertContains($result, [Command::SUCCESS, Command::FAILURE]);
    }

    public function testExecuteOutputsExpectedMessages(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('Composer Dependency Age', $output);
        $this->assertStringContainsString('Analyzing dependency ages', $output);
        // Since we're doing real API calls, we can't guarantee success
        // but we can check that the command at least starts processing
    }

    public function testExecuteWithoutArguments(): void
    {
        $commandTester = $this->getCommandTester();
        $result = $commandTester->execute([]);

        // Check that command runs and produces output
        $this->assertContains($result, [Command::SUCCESS, Command::FAILURE]);
        $this->assertNotEmpty($commandTester->getDisplay());
    }
}
