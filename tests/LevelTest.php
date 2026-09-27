<?php

declare(strict_types=1);

namespace XInfra\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XInfra\Level;

final class LevelTest extends TestCase
{
    public function testFromName(): void
    {
        self::assertSame(Level::Warning, Level::fromName(' Warning '));
        self::assertSame(Level::Critical, Level::fromName(Level::Critical));
    }

    public function testUnknownName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Level::fromName('warn');
    }

    public function testOrder(): void
    {
        $previous = null;

        foreach (Level::cases() as $level) {
            if ($previous !== null) {
                self::assertTrue($level->isAtLeast($previous));
                self::assertFalse($previous->isAtLeast($level));
            }

            $previous = $level;
        }
    }
}
