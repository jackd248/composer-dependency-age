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

use Composer\Console\Application as ComposerApplication;
use KonradMichalik\ComposerDependencyAge\Command\DependencyAgeCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 *
 * @package ComposerDependencyAge
 */
final class DependencyAgeCommandCacheTest extends TestCase
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

    public function testCommandHasNoCacheOption(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('no-cache'));

        $option = $definition->getOption('no-cache');
        $this->assertFalse($option->isValueRequired());
        $this->assertStringContainsString('cache', $option->getDescription());
    }

    public function testCommandWithNoCacheOption(): void
    {
        $commandTester = $this->getCommandTester();

        // Test that command runs with --no-cache option
        $result = $commandTester->execute(['--no-cache' => true]);

        // Should run without crashing
        $this->assertContains($result, [Command::SUCCESS, Command::FAILURE]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Composer Dependency Age', $output);
    }

    public function testCommandWithoutNoCacheOption(): void
    {
        $commandTester = $this->getCommandTester();

        // Test that command runs normally (with cache enabled)
        $result = $commandTester->execute([]);

        // Should run without crashing
        $this->assertContains($result, [Command::SUCCESS, Command::FAILURE]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Composer Dependency Age', $output);
    }

    public function testNoCacheOptionIsBoolean(): void
    {
        $commandTester = $this->getCommandTester();

        // Test with explicit true value
        $result1 = $commandTester->execute(['--no-cache' => true]);
        $this->assertContains($result1, [Command::SUCCESS, Command::FAILURE]);

        // Reset command tester for fresh execution
        $this->commandTester = null;
        $commandTester = $this->getCommandTester();

        // Test with just the flag (no value)
        $result2 = $commandTester->execute(['--no-cache']);
        $this->assertContains($result2, [Command::SUCCESS, Command::FAILURE]);
    }

    public function testCommandOptionsDocumentation(): void
    {
        $helpText = $this->command->getHelp();
        $this->assertNotEmpty($helpText);

        // Test command definition has the expected options
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('no-cache'));
        $this->assertTrue($definition->hasOption('no-colors'));
        $this->assertTrue($definition->hasOption('no-dev'));
        $this->assertTrue($definition->hasOption('format'));

        // Test option descriptions
        $this->assertStringContainsString('cache', $definition->getOption('no-cache')->getDescription());
        $this->assertStringContainsString('color', $definition->getOption('no-colors')->getDescription());
        $this->assertStringContainsString('dev', $definition->getOption('no-dev')->getDescription());
        $this->assertStringContainsString('format', $definition->getOption('format')->getDescription());
    }

    public function testCombiningCacheOptionWithOtherOptions(): void
    {
        $commandTester = $this->getCommandTester();

        // Test combining --no-cache with --format=json
        $result = $commandTester->execute([
            '--no-cache' => true,
            '--format' => 'json',
        ]);

        $this->assertContains($result, [Command::SUCCESS, Command::FAILURE]);

        // Reset for next test
        $this->commandTester = null;
        $commandTester = $this->getCommandTester();

        // Test combining --no-cache with --no-colors
        $result = $commandTester->execute([
            '--no-cache' => true,
            '--no-colors' => true,
        ]);

        $this->assertContains($result, [Command::SUCCESS, Command::FAILURE]);
    }

    public function testCommandOptionsMetadata(): void
    {
        $definition = $this->command->getDefinition();

        // Verify all expected options exist
        $expectedOptions = ['format', 'no-colors', 'no-dev', 'no-cache'];

        foreach ($expectedOptions as $optionName) {
            $this->assertTrue(
                $definition->hasOption($optionName),
                "Command should have option: {$optionName}",
            );
        }

        // Verify option types
        $this->assertFalse($definition->getOption('no-cache')->isValueRequired());
        $this->assertFalse($definition->getOption('no-colors')->isValueRequired());
        $this->assertFalse($definition->getOption('no-dev')->isValueRequired());
        $this->assertTrue($definition->getOption('format')->isValueRequired());
    }

    public function testCommandName(): void
    {
        $this->assertSame('dependency-age', $this->command->getName());
    }

    public function testCommandDescription(): void
    {
        $description = $this->command->getDescription();

        $this->assertNotEmpty($description);
        $this->assertStringContainsString('age', $description);
        $this->assertStringContainsString('dependencies', $description);
    }
}
