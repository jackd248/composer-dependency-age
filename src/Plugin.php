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

namespace KonradMichalik\ComposerDependencyAge;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

/**
 * Main plugin class that integrates with Composer.
 */
final class Plugin implements PluginInterface, Capable
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        // Plugin activation logic will be implemented here
        // Currently just registers the plugin with Composer
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Plugin deactivation logic
        // Currently no cleanup needed
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Plugin uninstall logic
        // Currently no cleanup needed
    }

    /**
     * @return array<string, string>
     */
    public function getCapabilities(): array
    {
        return [
            CommandProvider::class => \KonradMichalik\ComposerDependencyAge\CommandProvider::class,
        ];
    }
}
