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

namespace KonradMichalik\ComposerDependencyAge\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Main command for analyzing dependency ages.
 */
final class DependencyAgeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('dependency-age')
            ->setDescription('Analyze the age of your project dependencies')
            ->setHelp(
                'This command analyzes the age of all dependencies in your project and '.
                'provides insights about outdated packages.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Composer Dependency Age Plugin v0.1.0</info>');
        $output->writeln('<comment>Analyzing dependency ages...</comment>');

        // Placeholder implementation - will be expanded in Phase 2
        $output->writeln('<success>Analysis complete! (placeholder implementation)</success>');

        return self::SUCCESS;
    }
}
