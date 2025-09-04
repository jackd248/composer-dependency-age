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
use KonradMichalik\ComposerDependencyAge\Exception\PackageInfoException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-3.0-or-later
 *
 * @package ComposerDependencyAge
 */
final class PackageInfoExceptionTest extends TestCase
{
    public function testExceptionExtendsRuntimeException(): void
    {
        $exception = new PackageInfoException('Test message');

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertInstanceOf(Exception::class, $exception);
    }

    public function testExceptionWithMessage(): void
    {
        $message = 'Package info lookup failed';
        $exception = new PackageInfoException($message);

        $this->assertSame($message, $exception->getMessage());
    }

    public function testExceptionWithMessageAndCode(): void
    {
        $message = 'Package not found';
        $code = 404;
        $exception = new PackageInfoException($message, $code);

        $this->assertSame($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
    }

    public function testExceptionWithMessageCodeAndPrevious(): void
    {
        $previous = new RuntimeException('API error');
        $message = 'Package lookup failed';
        $code = 500;
        $exception = new PackageInfoException($message, $code, $previous);

        $this->assertSame($message, $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
