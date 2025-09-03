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

namespace KonradMichalik\ComposerDependencyAge\Tests\Output;

use InvalidArgumentException;
use Iterator;
use KonradMichalik\ComposerDependencyAge\Output\ColorFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Test the ColorFormatter class.
 */
final class ColorFormatterTest extends TestCase
{
    private ColorFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ColorFormatter(true);
    }

    public function testConstructorWithColorsEnabled(): void
    {
        $formatter = new ColorFormatter(true);
        $this->assertTrue($formatter->areColorsEnabled());
    }

    public function testConstructorWithColorsDisabled(): void
    {
        $formatter = new ColorFormatter(false);
        $this->assertFalse($formatter->areColorsEnabled());
    }

    public function testSetColorsEnabled(): void
    {
        $this->formatter->setColorsEnabled(false);
        $this->assertFalse($this->formatter->areColorsEnabled());

        $this->formatter->setColorsEnabled(true);
        $this->assertTrue($this->formatter->areColorsEnabled());
    }

    public function testColorFormattingWithColorsEnabled(): void
    {
        $result = $this->formatter->color('test', 'red');

        $this->assertStringStartsWith("\033[31m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
        $this->assertStringContainsString('test', $result);
    }

    public function testColorFormattingWithColorsDisabled(): void
    {
        $this->formatter->setColorsEnabled(false);
        $result = $this->formatter->color('test', 'old');

        $this->assertSame('test', $result);
        $this->assertStringNotContainsString("\033[", $result);
    }

    public function testInvalidColorThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown color: invalid');

        $this->formatter->color('test', 'invalid');
    }

    public function testBackgroundColorFormatting(): void
    {
        $result = $this->formatter->bgColor('test', 'blue');

        $this->assertStringStartsWith("\033[44m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
        $this->assertStringContainsString('test', $result);
    }

    public function testBackgroundColorWithColorsDisabled(): void
    {
        $this->formatter->setColorsEnabled(false);
        $result = $this->formatter->bgColor('test', 'blue');

        $this->assertSame('test', $result);
    }

    public function testInvalidBackgroundColorThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown background color: invalid');

        $this->formatter->bgColor('test', 'invalid');
    }

    public function testStyleFormatting(): void
    {
        $result = $this->formatter->style('test', 'bold');

        $this->assertStringStartsWith("\033[1m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
        $this->assertStringContainsString('test', $result);
    }

    public function testStyleWithColorsDisabled(): void
    {
        $this->formatter->setColorsEnabled(false);
        $result = $this->formatter->style('test', 'bold');

        $this->assertSame('test', $result);
    }

    public function testInvalidStyleThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown style: invalid');

        $this->formatter->style('test', 'invalid');
    }

    public function testMultipleFormatsFormatting(): void
    {
        $result = $this->formatter->format('test', ['red', 'bold']);

        $this->assertStringStartsWith("\033[31;1m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
        $this->assertStringContainsString('test', $result);
    }

    public function testMultipleFormatsWithColorsDisabled(): void
    {
        $this->formatter->setColorsEnabled(false);
        $result = $this->formatter->format('test', ['old', 'bold']);

        $this->assertSame('test', $result);
    }

    public function testMultipleFormatsWithInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown format: invalid');

        $this->formatter->format('test', ['red', 'invalid']);
    }

    public function testEmptyFormatsArray(): void
    {
        $result = $this->formatter->format('test', []);

        $this->assertSame('test', $result);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ratingCategoryProvider')]
    public function testFormatRating(string $category, string $expectedColorCode): void
    {
        $result = $this->formatter->formatRating('test', $category);

        if ('' !== $expectedColorCode) {
            $this->assertStringStartsWith("\033[{$expectedColorCode}m", $result);
            $this->assertStringEndsWith("\033[0m", $result);
        } else {
            $this->assertSame('test', $result);
        }
        $this->assertStringContainsString('test', $result);
    }

    public static function ratingCategoryProvider(): Iterator
    {
        yield 'current' => ['current', '92'];
        yield 'medium' => ['medium', '93'];
        yield 'old' => ['old', '91'];
        yield 'unknown' => ['unknown', '37'];
        yield 'invalid' => ['invalid', ''];
    }

    public function testFormatRatingWithColorsDisabled(): void
    {
        $this->formatter->setColorsEnabled(false);
        $result = $this->formatter->formatRating('test', 'current');

        $this->assertSame('test', $result);
    }

    public function testSuccessFormatting(): void
    {
        $result = $this->formatter->success('Success!');

        $this->assertStringStartsWith("\033[92;1m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
        $this->assertStringContainsString('Success!', $result);
    }

    public function testWarningFormatting(): void
    {
        $result = $this->formatter->warning('Warning!');

        $this->assertStringStartsWith("\033[93;1m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
        $this->assertStringContainsString('Warning!', $result);
    }

    public function testErrorFormatting(): void
    {
        $result = $this->formatter->error('Error!');

        $this->assertStringStartsWith("\033[91;1m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
        $this->assertStringContainsString('Error!', $result);
    }

    public function testInfoFormatting(): void
    {
        $result = $this->formatter->info('Info!');

        $this->assertStringStartsWith("\033[94;1m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
        $this->assertStringContainsString('Info!', $result);
    }

    public function testHeaderFormatting(): void
    {
        $result = $this->formatter->header('Header');

        $this->assertStringStartsWith("\033[1;4m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
        $this->assertStringContainsString('Header', $result);
    }

    public function testMutedFormatting(): void
    {
        $result = $this->formatter->muted('Muted text');

        $this->assertStringStartsWith("\033[2m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
        $this->assertStringContainsString('Muted text', $result);
    }

    public function testHighlightFormatting(): void
    {
        $result = $this->formatter->highlight('Highlighted');

        $this->assertStringStartsWith("\033[30;103m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
        $this->assertStringContainsString('Highlighted', $result);
    }

    public function testStripColors(): void
    {
        $coloredText = "\033[31mRed text\033[0m and \033[32mGreen text\033[0m";
        $result = $this->formatter->stripColors($coloredText);

        $this->assertSame('Red text and Green text', $result);
    }

    public function testStripColorsWithNoColors(): void
    {
        $plainText = 'Plain text';
        $result = $this->formatter->stripColors($plainText);

        $this->assertSame('Plain text', $result);
    }

    public function testGetTextLength(): void
    {
        $coloredText = "\033[31mRed\033[0m";
        $result = $this->formatter->getTextLength($coloredText);

        $this->assertSame(3, $result); // Only counts 'Red', not the ANSI codes
    }

    public function testGetTextLengthWithPlainText(): void
    {
        $plainText = 'Plain text';
        $result = $this->formatter->getTextLength($plainText);

        $this->assertSame(10, $result);
    }

    public function testGetAvailableColors(): void
    {
        $colors = $this->formatter->getAvailableColors();

        $this->assertIsArray($colors);
        $this->assertContains('red', $colors);
        $this->assertContains('green', $colors);
        $this->assertContains('blue', $colors);
        $this->assertContains('bright_red', $colors);
        $this->assertGreaterThan(8, count($colors)); // Should have regular + bright colors
    }

    public function testGetAvailableBackgroundColors(): void
    {
        $bgColors = $this->formatter->getAvailableBackgroundColors();

        $this->assertIsArray($bgColors);
        $this->assertContains('red', $bgColors);
        $this->assertContains('green', $bgColors);
        $this->assertContains('blue', $bgColors);
        $this->assertContains('bright_red', $bgColors);
        $this->assertGreaterThan(8, count($bgColors));
    }

    public function testGetAvailableStyles(): void
    {
        $styles = $this->formatter->getAvailableStyles();

        $this->assertIsArray($styles);
        $this->assertContains('bold', $styles);
        $this->assertContains('underline', $styles);
        $this->assertContains('italic', $styles);
        $this->assertGreaterThan(5, count($styles));
    }

    public function testProgressBar(): void
    {
        $result = $this->formatter->progressBar(50, 100, 20);

        $this->assertStringContainsString('[', $result);
        $this->assertStringContainsString(']', $result);
        $this->assertStringContainsString('50/100', $result);
        $this->assertStringContainsString('█', $result); // Filled character
        $this->assertStringContainsString('░', $result); // Empty character
    }

    public function testProgressBarWithColorsDisabled(): void
    {
        $this->formatter->setColorsEnabled(false);
        $result = $this->formatter->progressBar(25, 100, 20);

        $this->assertStringContainsString('[', $result);
        $this->assertStringContainsString(']', $result);
        $this->assertStringContainsString('25/100', $result);
        $this->assertStringNotContainsString("\033[", $result);
    }

    public function testProgressBarFullyEmpty(): void
    {
        $result = $this->formatter->progressBar(0, 100, 10);

        $this->assertStringContainsString('0/100', $result);
        $this->assertStringContainsString('░', $result);
    }

    public function testProgressBarFullyFilled(): void
    {
        $result = $this->formatter->progressBar(100, 100, 10);

        $this->assertStringContainsString('100/100', $result);
        $this->assertStringContainsString('█', $result);
    }

    public function testRainbow(): void
    {
        $result = $this->formatter->rainbow('Rainbow');

        $this->assertStringContainsString('Rainbow', $this->formatter->stripColors($result));
        $this->assertStringContainsString("\033[", $result); // Should contain ANSI codes

        // Should contain multiple color codes for different characters
        $colorCount = substr_count($result, "\033[");
        $this->assertGreaterThan(3, $colorCount); // Multiple colors for multiple characters
    }

    public function testRainbowWithColorsDisabled(): void
    {
        $this->formatter->setColorsEnabled(false);
        $result = $this->formatter->rainbow('Rainbow');

        $this->assertSame('Rainbow', $result);
        $this->assertStringNotContainsString("\033[", $result);
    }

    public function testRainbowWithSpaces(): void
    {
        $result = $this->formatter->rainbow('A B C');

        $strippedResult = $this->formatter->stripColors($result);
        $this->assertSame('A B C', $strippedResult);

        // Spaces should not have color codes around them
        $this->assertStringContainsString(' ', $result);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brightColorProvider')]
    public function testBrightColors(string $color, int $expectedCode): void
    {
        $result = $this->formatter->color('test', $color);

        $this->assertStringStartsWith("\033[{$expectedCode}m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
    }

    public static function brightColorProvider(): Iterator
    {
        yield 'bright_red' => ['bright_red', 91];
        yield 'bright_green' => ['bright_green', 92];
        yield 'bright_yellow' => ['bright_yellow', 93];
        yield 'bright_blue' => ['bright_blue', 94];
        yield 'bright_magenta' => ['bright_magenta', 95];
        yield 'bright_cyan' => ['bright_cyan', 96];
        yield 'bright_white' => ['bright_white', 97];
    }

    public function testComplexMultipleFormats(): void
    {
        $result = $this->formatter->format('test', ['red', 'bright_yellow', 'bold', 'underline']);

        // Should contain multiple codes separated by semicolons
        $this->assertStringStartsWith("\033[", $result);
        $this->assertStringContainsString(';', $result);
        $this->assertStringEndsWith("\033[0m", $result);
    }

    public function testIsTerminalColorSupported(): void
    {
        // This is environment-dependent, so we just test that it returns a boolean
        $result = ColorFormatter::isTerminalColorSupported();
        $this->assertIsBool($result);
    }

    public function testShouldUseColors(): void
    {
        // This is environment-dependent, so we just test that it returns a boolean
        $result = ColorFormatter::shouldUseColors();
        $this->assertIsBool($result);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('styleProvider')]
    public function testAllStyles(string $style, int $expectedCode): void
    {
        $result = $this->formatter->style('test', $style);

        $this->assertStringStartsWith("\033[{$expectedCode}m", $result);
        $this->assertStringEndsWith("\033[0m", $result);
    }

    public static function styleProvider(): Iterator
    {
        yield 'bold' => ['bold', 1];
        yield 'dim' => ['dim', 2];
        yield 'italic' => ['italic', 3];
        yield 'underline' => ['underline', 4];
        yield 'blink' => ['blink', 5];
        yield 'reverse' => ['reverse', 7];
        yield 'strikethrough' => ['strikethrough', 9];
    }
}
