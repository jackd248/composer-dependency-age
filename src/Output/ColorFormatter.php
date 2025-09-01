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

namespace KonradMichalik\ComposerDependencyAge\Output;

use InvalidArgumentException;

/**
 * Utility for formatting text with ANSI colors in terminal output.
 */
class ColorFormatter
{
    // ANSI Color Codes
    private const COLORS = [
        'black' => 30,
        'red' => 31,
        'green' => 32,
        'yellow' => 33,
        'blue' => 34,
        'magenta' => 35,
        'cyan' => 36,
        'white' => 37,
        'bright_black' => 90,
        'bright_red' => 91,
        'bright_green' => 92,
        'bright_yellow' => 93,
        'bright_blue' => 94,
        'bright_magenta' => 95,
        'bright_cyan' => 96,
        'bright_white' => 97,
    ];

    // ANSI Background Color Codes
    private const BG_COLORS = [
        'black' => 40,
        'red' => 41,
        'green' => 42,
        'yellow' => 43,
        'blue' => 44,
        'magenta' => 45,
        'cyan' => 46,
        'white' => 47,
        'bright_black' => 100,
        'bright_red' => 101,
        'bright_green' => 102,
        'bright_yellow' => 103,
        'bright_blue' => 104,
        'bright_magenta' => 105,
        'bright_cyan' => 106,
        'bright_white' => 107,
    ];

    // ANSI Style Codes
    private const STYLES = [
        'bold' => 1,
        'dim' => 2,
        'italic' => 3,
        'underline' => 4,
        'blink' => 5,
        'reverse' => 7,
        'strikethrough' => 9,
    ];

    private const RESET = "\033[0m";

    public function __construct(private bool $colorsEnabled = true) {}

    /**
     * Enable or disable color output.
     */
    public function setColorsEnabled(bool $enabled): void
    {
        $this->colorsEnabled = $enabled;
    }

    /**
     * Check if colors are enabled.
     */
    public function areColorsEnabled(): bool
    {
        return $this->colorsEnabled;
    }

    /**
     * Format text with color.
     */
    public function color(string $text, string $color): string
    {
        if (!$this->colorsEnabled) {
            return $text;
        }

        if (!isset(self::COLORS[$color])) {
            throw new InvalidArgumentException("Unknown color: {$color}");
        }

        return "\033[".self::COLORS[$color]."m{$text}".self::RESET;
    }

    /**
     * Format text with background color.
     */
    public function bgColor(string $text, string $bgColor): string
    {
        if (!$this->colorsEnabled) {
            return $text;
        }

        if (!isset(self::BG_COLORS[$bgColor])) {
            throw new InvalidArgumentException("Unknown background color: {$bgColor}");
        }

        return "\033[".self::BG_COLORS[$bgColor]."m{$text}".self::RESET;
    }

    /**
     * Format text with style.
     */
    public function style(string $text, string $style): string
    {
        if (!$this->colorsEnabled) {
            return $text;
        }

        if (!isset(self::STYLES[$style])) {
            throw new InvalidArgumentException("Unknown style: {$style}");
        }

        return "\033[".self::STYLES[$style]."m{$text}".self::RESET;
    }

    /**
     * Format text with multiple styles/colors.
     */
    /**
     * @param array<string> $formats
     */
    public function format(string $text, array $formats): string
    {
        if (!$this->colorsEnabled) {
            return $text;
        }

        $codes = [];

        foreach ($formats as $format) {
            if (isset(self::COLORS[$format])) {
                $codes[] = self::COLORS[$format];
            } elseif (isset(self::BG_COLORS[$format])) {
                $codes[] = self::BG_COLORS[$format];
            } elseif (isset(self::STYLES[$format])) {
                $codes[] = self::STYLES[$format];
            } else {
                throw new InvalidArgumentException("Unknown format: {$format}");
            }
        }

        if (empty($codes)) {
            return $text;
        }

        return "\033[".implode(';', $codes)."m{$text}".self::RESET;
    }

    /**
     * Format text based on rating category.
     */
    public function formatRating(string $text, string $category): string
    {
        if (!$this->colorsEnabled) {
            return $text;
        }

        return match ($category) {
            'green' => $this->color($text, 'bright_green'),
            'yellow' => $this->color($text, 'bright_yellow'),
            'red' => $this->color($text, 'bright_red'),
            'unknown' => $this->color($text, 'white'),
            default => $text,
        };
    }

    /**
     * Format success message.
     */
    public function success(string $text): string
    {
        return $this->format($text, ['bright_green', 'bold']);
    }

    /**
     * Format warning message.
     */
    public function warning(string $text): string
    {
        return $this->format($text, ['bright_yellow', 'bold']);
    }

    /**
     * Format error message.
     */
    public function error(string $text): string
    {
        return $this->format($text, ['bright_red', 'bold']);
    }

    /**
     * Format info message.
     */
    public function info(string $text): string
    {
        return $this->format($text, ['bright_blue', 'bold']);
    }

    /**
     * Format header text.
     */
    public function header(string $text): string
    {
        return $this->format($text, ['bold', 'underline']);
    }

    /**
     * Format muted/dim text.
     */
    public function muted(string $text): string
    {
        return $this->style($text, 'dim');
    }

    /**
     * Format highlighted text.
     */
    public function highlight(string $text): string
    {
        if (!$this->colorsEnabled) {
            return $text;
        }

        // Use black text on bright yellow background
        return "\033[30;103m{$text}".self::RESET;
    }

    /**
     * Strip ANSI color codes from text.
     */
    public function stripColors(string $text): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    /**
     * Get length of text without ANSI codes.
     */
    public function getTextLength(string $text): int
    {
        return strlen($this->stripColors($text));
    }

    /**
     * Check if terminal supports colors.
     */
    public static function isTerminalColorSupported(): bool
    {
        // Check for common environment variables that indicate color support
        $colorTerms = ['xterm', 'xterm-256color', 'screen', 'linux', 'cygwin'];
        $term = getenv('TERM');

        if ($term && in_array($term, $colorTerms, true)) {
            return true;
        }

        // Check for force color environment variables
        if (getenv('FORCE_COLOR') || getenv('CLICOLOR_FORCE')) {
            return true;
        }

        // Check if NO_COLOR is set (disables colors)
        if (getenv('NO_COLOR')) {
            return false;
        }

        // Check if we're in a CI environment that supports colors
        $colorTerms256 = ['xterm-256color', 'screen-256color'];
        if ($term && in_array($term, $colorTerms256, true)) {
            return true;
        }

        // On Windows, check for ANSICON or Windows Terminal
        if (PHP_OS_FAMILY === 'Windows') {
            return getenv('ANSICON') || getenv('WT_SESSION') || getenv('ConEmuTask');
        }

        // Default to true for Unix-like systems with a TTY
        return function_exists('posix_isatty') && posix_isatty(STDOUT);
    }

    /**
     * Auto-detect if colors should be enabled based on terminal.
     */
    public static function shouldUseColors(): bool
    {
        return self::isTerminalColorSupported();
    }

    /**
     * Get available color names.
     */
    /**
     * @return array<string>
     */
    public function getAvailableColors(): array
    {
        return array_keys(self::COLORS);
    }

    /**
     * Get available background color names.
     */
    /**
     * @return array<string>
     */
    public function getAvailableBackgroundColors(): array
    {
        return array_keys(self::BG_COLORS);
    }

    /**
     * Get available style names.
     */
    /**
     * @return array<string>
     */
    public function getAvailableStyles(): array
    {
        return array_keys(self::STYLES);
    }

    /**
     * Create a progress bar with colors.
     */
    public function progressBar(int $current, int $total, int $width = 40, string $fillColor = 'green', string $emptyColor = 'white'): string
    {
        if (!$this->colorsEnabled) {
            $filled = str_repeat('█', intval($width * $current / $total));
            $empty = str_repeat('░', $width - strlen($filled));

            return "[{$filled}{$empty}] {$current}/{$total}";
        }

        $filledWidth = intval($width * $current / $total);
        $filled = $this->color(str_repeat('█', $filledWidth), $fillColor);
        $empty = $this->color(str_repeat('░', $width - $filledWidth), $emptyColor);

        return "[{$filled}{$empty}] {$current}/{$total}";
    }

    /**
     * Create a rainbow effect on text.
     */
    public function rainbow(string $text): string
    {
        if (!$this->colorsEnabled) {
            return $text;
        }

        $colors = ['red', 'yellow', 'green', 'cyan', 'blue', 'magenta'];
        $result = '';
        $colorIndex = 0;

        for ($i = 0; $i < strlen($text); ++$i) {
            $char = $text[$i];
            if (' ' !== $char) {
                $result .= $this->color($char, $colors[$colorIndex % count($colors)]);
                ++$colorIndex;
            } else {
                $result .= $char;
            }
        }

        return $result;
    }
}
