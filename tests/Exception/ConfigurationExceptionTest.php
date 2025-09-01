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

namespace KonradMichalik\ComposerDependencyAge\Tests\Exception;

use Exception;
use InvalidArgumentException;
use KonradMichalik\ComposerDependencyAge\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test the ConfigurationException class.
 */
final class ConfigurationExceptionTest extends TestCase
{
    public function testExceptionExtendsRuntimeException(): void
    {
        $exception = new ConfigurationException('Test message');

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertInstanceOf(Exception::class, $exception);
    }

    public function testExceptionWithMessage(): void
    {
        $message = 'Configuration validation failed';
        $exception = new ConfigurationException($message);

        $this->assertSame($message, $exception->getMessage());
    }

    public function testExceptionWithMessageAndCode(): void
    {
        $message = 'Invalid threshold configuration';
        $code = 400;
        $exception = new ConfigurationException($message, $code);

        $this->assertSame($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
    }

    public function testExceptionWithMessageCodeAndPrevious(): void
    {
        $previous = new InvalidArgumentException('Invalid value');
        $message = 'Configuration error';
        $code = 500;
        $exception = new ConfigurationException($message, $code, $previous);

        $this->assertSame($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
