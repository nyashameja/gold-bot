<?php

declare(strict_types=1);

namespace GoldBot\Infrastructure\Logging;

/**
 * PSR-3 severity levels, ordered so they can be compared numerically.
 */
enum LogLevel: string
{
    case Debug     = 'debug';
    case Info      = 'info';
    case Notice    = 'notice';
    case Warning   = 'warning';
    case Error     = 'error';
    case Critical  = 'critical';
    case Alert     = 'alert';
    case Emergency = 'emergency';

    public function severity(): int
    {
        return match ($this) {
            self::Debug     => 0,
            self::Info      => 1,
            self::Notice    => 2,
            self::Warning   => 3,
            self::Error     => 4,
            self::Critical  => 5,
            self::Alert     => 6,
            self::Emergency => 7,
        };
    }

    public function isAtLeast(self $minimum): bool
    {
        return $this->severity() >= $minimum->severity();
    }

    public static function fromName(string $name, self $fallback = self::Info): self
    {
        return self::tryFrom(strtolower($name)) ?? $fallback;
    }
}
