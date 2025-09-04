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

namespace KonradMichalik\ComposerDependencyAge\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Throwable;

/**
 * DependencyAgePlugin.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 */
final class DependencyAgePlugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Plugin deactivation cleanup if needed
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Plugin uninstallation cleanup if needed
    }

    /**
     * Register event subscribers.
     *
     * @return array<string, array<int, array<int, string|int>>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => [
                ['onPostInstall', 0],
            ],
            ScriptEvents::POST_UPDATE_CMD => [
                ['onPostUpdate', 0],
            ],
        ];
    }

    /**
     * Handle post-install event.
     */
    public function onPostInstall(): void
    {
        $this->runDependencyAgeCheck('install');
    }

    /**
     * Handle post-update event.
     */
    public function onPostUpdate(): void
    {
        $this->runDependencyAgeCheck('update');
    }

    /**
     * Run dependency age check after composer operations.
     */
    private function runDependencyAgeCheck(string $operation): void
    {
        try {
            $eventHandler = new ComposerEventHandler($this->composer, $this->io);
            $eventHandler->handlePostOperation($operation);
        } catch (Throwable $e) {
            // Don't fail the composer operation, just show a warning
            $this->io->writeError(
                '<warning>Dependency age check failed: '.$e->getMessage().'</warning>',
            );

            if ($this->io->isVerbose()) {
                $this->io->writeError('<warning>'.$e->getTraceAsString().'</warning>');
            }
        }
    }

    /**
     * Get plugin capabilities.
     *
     * @return array<string, class-string>
     */
    public function getCapabilities(): array
    {
        return [
            CommandProvider::class => DependencyAgeCommandProvider::class,
        ];
    }
}
