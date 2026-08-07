<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use Paragon\Core\Logging\LoggerInterface;
use Paragon\Core\Logging\LogLevel;
use Stringable;

/**
 * Discards everything. Used where a unit under test needs a logger it will
 * never meaningfully exercise — keeping the test free of filesystem writes.
 */
final class NullTestLogger implements LoggerInterface
{
    public function log(LogLevel $level, string|Stringable $message, array $context = []): void
    {
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
    }

    public function info(string|Stringable $message, array $context = []): void
    {
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
    }

    public function error(string|Stringable $message, array $context = []): void
    {
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
    }

    public function withContext(array $context): LoggerInterface
    {
        return $this;
    }
}
