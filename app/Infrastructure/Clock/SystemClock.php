<?php

declare(strict_types=1);

namespace GoldBot\Infrastructure\Clock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Wall-clock time, pinned to UTC.
 *
 * The timezone is fixed here rather than taken from php.ini or APP_TIMEZONE:
 * storage is UTC by convention (docs/02 §1), and a misconfigured server
 * timezone must not be able to shift stored timestamps.
 */
final class SystemClock implements ClockInterface
{
    private readonly DateTimeZone $utc;

    public function __construct()
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->utc);
    }

    public function timestamp(): int
    {
        return time();
    }
}
