<?php

declare(strict_types=1);

namespace Paragon\Core\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Paragon\Core\Clock\FrozenClock;
use Paragon\Core\Clock\SystemClock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    /**
     * Storage is UTC by convention (docs/02 §1). The clock pins the zone
     * itself so a misconfigured server timezone cannot shift stored
     * timestamps.
     */
    public function test_the_system_clock_is_always_utc_regardless_of_php_default(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_the_system_clock_timestamp_tracks_now(): void
    {
        $clock = new SystemClock();

        self::assertEqualsWithDelta(time(), $clock->timestamp(), 2);
    }

    public function test_a_frozen_clock_does_not_move_on_its_own(): void
    {
        $clock = new FrozenClock('2026-03-08 13:45:00');

        $first = $clock->now();
        usleep(1000);

        self::assertEquals($first, $clock->now());
        self::assertSame('2026-03-08 13:45:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function test_it_advances_by_an_iso_duration(): void
    {
        $clock = new FrozenClock('2026-03-08 13:45:00');
        $clock->advance('PT15M');

        self::assertSame('2026-03-08 14:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function test_it_advances_and_rewinds_by_seconds(): void
    {
        $clock = new FrozenClock('2026-03-08 13:45:00');

        $clock->advanceSeconds(90);
        self::assertSame('2026-03-08 13:46:30', $clock->now()->format('Y-m-d H:i:s'));

        $clock->advanceSeconds(-90);
        self::assertSame('2026-03-08 13:45:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    /**
     * The backtester replays historical moments through the same code the live
     * engine uses (ADR-04), so a non-UTC input must be normalised rather than
     * silently retaining its offset.
     */
    public function test_it_normalises_a_non_utc_input_to_utc(): void
    {
        $newYorkNoon = new DateTimeImmutable('2026-03-08 12:00:00', new DateTimeZone('America/New_York'));
        $clock = new FrozenClock($newYorkNoon);

        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
        self::assertSame($newYorkNoon->getTimestamp(), $clock->timestamp());
    }

    public function test_it_can_be_repositioned(): void
    {
        $clock = new FrozenClock('2026-01-01 00:00:00');
        $clock->setTo('2026-12-25 09:30:00');

        self::assertSame('2026-12-25 09:30:00', $clock->now()->format('Y-m-d H:i:s'));
    }
}
