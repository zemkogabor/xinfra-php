<?php

declare(strict_types=1);

namespace XInfra;

use InvalidArgumentException;

/**
 * The eight PSR-3 log levels, from least to most severe.
 */
enum Level: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    /**
     * Accepts a level or a level name in any letter case.
     */
    public static function fromName(self|string $level): self
    {
        if ($level instanceof self) {
            return $level;
        }

        $parsed = self::tryFrom(strtolower(trim($level)));

        if ($parsed === null) {
            throw new InvalidArgumentException('Unknown log level: ' . $level);
        }

        return $parsed;
    }

    public function severity(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Notice => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Critical => 5,
            self::Alert => 6,
            self::Emergency => 7,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->severity() >= $other->severity();
    }
}
