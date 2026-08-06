<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Session\SessionResolver;
use GoldBot\Domain\Session\TradingSession;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SessionResolverTest extends TestCase
{
    private function resolver(): SessionResolver
    {
        // Mirrors the seeded market_sessions rows, in definition order.
        return SessionResolver::fromRows([
            ['code' => 'SYDNEY',   'name' => 'Sydney',   'open_time' => '07:00:00', 'close_time' => '16:00:00', 'timezone' => 'Australia/Sydney'],
            ['code' => 'TOKYO',    'name' => 'Tokyo',    'open_time' => '09:00:00', 'close_time' => '18:00:00', 'timezone' => 'Asia/Tokyo'],
            ['code' => 'LONDON',   'name' => 'London',   'open_time' => '08:00:00', 'close_time' => '16:30:00', 'timezone' => 'Europe/London'],
            ['code' => 'NEW_YORK', 'name' => 'New York', 'open_time' => '08:00:00', 'close_time' => '17:00:00', 'timezone' => 'America/New_York'],
        ]);
    }

    private function utc(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }

    /**
     * The bug this design exists to prevent (docs/02 §4).
     *
     * In 2026 US DST begins 8 March but UK/EU DST begins 29 March, so for
     * three weeks New York is on EDT while London is still on GMT. Any code
     * using fixed UTC offsets is wrong for exactly this window.
     *
     * At 12:30 UTC:
     *   3 March  — NY is 07:30 EST, still closed. London alone.
     *   10 March — NY is 08:30 EDT, open. Both.
     *
     * A fixed-offset implementation cannot produce both answers.
     */
    public function test_new_york_opens_an_hour_earlier_in_utc_once_us_dst_starts(): void
    {
        $resolver = $this->resolver();

        self::assertSame(
            ['LONDON'],
            $resolver->activeCodesAt($this->utc('2026-03-03 12:30:00')),
            'Before US DST, 12:30 UTC is 07:30 in New York — still closed.'
        );

        self::assertSame(
            ['LONDON', 'NEW_YORK'],
            $resolver->activeCodesAt($this->utc('2026-03-10 12:30:00')),
            'After US DST but before UK DST, 12:30 UTC is 08:30 in New York — open.'
        );
    }

    public function test_london_shifts_an_hour_earlier_in_utc_once_uk_dst_starts(): void
    {
        $resolver = $this->resolver();

        // London opens 08:00 local. On GMT that is 08:00 UTC; on BST, 07:00 UTC.
        self::assertNotContains('LONDON', $resolver->activeCodesAt($this->utc('2026-03-10 07:30:00')));
        self::assertContains('LONDON', $resolver->activeCodesAt($this->utc('2026-04-07 07:30:00')));
    }

    public function test_the_utc_offset_reflects_dst_at_the_given_moment(): void
    {
        $newYork = new TradingSession('NEW_YORK', 'New York', '08:00:00', '17:00:00', 'America/New_York');

        self::assertSame(-300, $newYork->utcOffsetMinutes($this->utc('2026-01-15 12:00:00')), 'EST is UTC-5.');
        self::assertSame(-240, $newYork->utcOffsetMinutes($this->utc('2026-07-15 12:00:00')), 'EDT is UTC-4.');
    }

    public function test_overlapping_sessions_are_all_reported(): void
    {
        // Mid-July: London on BST, New York on EDT. 14:00 UTC is 15:00 London
        // and 10:00 New York — the overlap.
        $active = $this->resolver()->activeCodesAt($this->utc('2026-07-15 14:00:00'));

        self::assertContains('LONDON', $active);
        self::assertContains('NEW_YORK', $active);
    }

    /**
     * Attribution must be deterministic, or the per-session performance
     * breakdown compares unlike periods.
     */
    public function test_the_overlap_is_attributed_to_new_york(): void
    {
        $primary = $this->resolver()->primaryAt($this->utc('2026-07-15 14:00:00'));

        self::assertNotNull($primary);
        self::assertSame('NEW_YORK', $primary->code);
    }

    public function test_primary_is_null_when_nothing_is_open(): void
    {
        // 03:00 UTC on a July Wednesday: London and New York closed, Sydney
        // (13:00 AEST) and Tokyo (12:00 JST) both open — so pick a true gap.
        $resolver = new SessionResolver([
            new TradingSession('LONDON', 'London', '08:00:00', '16:30:00', 'Europe/London'),
        ]);

        self::assertNull($resolver->primaryAt($this->utc('2026-07-15 03:00:00')));
        self::assertFalse($resolver->isAnyOpenAt($this->utc('2026-07-15 03:00:00')));
        self::assertSame([], $resolver->activeCodesAt($this->utc('2026-07-15 03:00:00')));
    }

    public function test_a_session_is_open_at_its_opening_minute_and_closed_at_its_closing_minute(): void
    {
        $session = new TradingSession('LONDON', 'London', '08:00:00', '16:30:00', 'Europe/London');

        // January — London on GMT, so local time equals UTC.
        self::assertTrue($session->isOpenAt($this->utc('2026-01-15 08:00:00')));
        self::assertTrue($session->isOpenAt($this->utc('2026-01-15 16:29:00')));
        self::assertFalse($session->isOpenAt($this->utc('2026-01-15 07:59:00')));
        self::assertFalse($session->isOpenAt($this->utc('2026-01-15 16:30:00')), 'The close is exclusive.');
    }

    /**
     * Sydney's 07:00–16:00 local window sits either side of midnight UTC for
     * much of the year, which is where a naive UTC-minutes comparison breaks.
     */
    public function test_a_session_spanning_midnight_utc_is_handled(): void
    {
        $sydney = new TradingSession('SYDNEY', 'Sydney', '07:00:00', '16:00:00', 'Australia/Sydney');

        // July: AEST is UTC+10, so 07:00–16:00 local is 21:00–06:00 UTC.
        self::assertTrue($sydney->isOpenAt($this->utc('2026-07-14 22:00:00')));
        self::assertTrue($sydney->isOpenAt($this->utc('2026-07-15 03:00:00')));
        self::assertFalse($sydney->isOpenAt($this->utc('2026-07-15 07:00:00')));
    }

    public function test_a_session_defined_across_local_midnight_wraps(): void
    {
        $overnight = new TradingSession('OVERNIGHT', 'Overnight', '22:00:00', '04:00:00', 'UTC');

        self::assertTrue($overnight->isOpenAt($this->utc('2026-07-15 23:00:00')));
        self::assertTrue($overnight->isOpenAt($this->utc('2026-07-15 01:00:00')));
        self::assertFalse($overnight->isOpenAt($this->utc('2026-07-15 12:00:00')));
    }

    public function test_it_rejects_an_unknown_timezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown IANA timezone');

        new TradingSession('BAD', 'Bad', '08:00:00', '16:00:00', 'Mars/Olympus_Mons');
    }

    public function test_it_rejects_a_malformed_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HH:MM');

        new TradingSession('BAD', 'Bad', '8am', '16:00:00', 'UTC');
    }

    public function test_it_accepts_times_without_seconds(): void
    {
        $session = new TradingSession('X', 'X', '08:00', '16:00', 'UTC');

        self::assertTrue($session->isOpenAt($this->utc('2026-01-15 09:00:00')));
    }
}
