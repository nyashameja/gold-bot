<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Timeframe;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CandleTest extends TestCase
{
    private function utc(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }

    private function candle(
        string $open,
        string $high,
        string $low,
        string $close,
        string $at = '2026-08-06 10:00:00',
        bool $closed = true
    ): Candle {
        return new Candle(
            $this->utc($at),
            $this->utc($at)->modify('+14 minutes 59 seconds'),
            $open,
            $high,
            $low,
            $close,
            '0',
            $closed
        );
    }

    /**
     * Bad provider data must be rejected at the boundary. A bar whose high is
     * below its low reaching the indicator pipeline looks plausible all the
     * way to a signal.
     */
    public function test_a_bar_with_high_below_low_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('high');

        $this->candle('3300', '3290', '3310', '3305');
    }

    public function test_a_bar_with_close_outside_its_range_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('close');

        $this->candle('3300', '3310', '3295', '3320');
    }

    public function test_a_bar_with_open_outside_its_range_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('open');

        $this->candle('3280', '3310', '3295', '3300');
    }

    public function test_a_doji_where_all_prices_are_equal_is_valid(): void
    {
        $candle = $this->candle('3300', '3300', '3300', '3300');

        self::assertSame(0.0, $candle->range());
        self::assertSame(0.0, $candle->bodySize());
        self::assertFalse($candle->isBullish());
        self::assertFalse($candle->isBearish());
    }

    public function test_direction_and_geometry(): void
    {
        $bull = $this->candle('3300', '3320', '3295', '3315');

        self::assertTrue($bull->isBullish());
        self::assertFalse($bull->isBearish());
        self::assertEqualsWithDelta(25.0, $bull->range(), 0.0001);
        self::assertEqualsWithDelta(15.0, $bull->bodySize(), 0.0001);
        self::assertEqualsWithDelta(5.0, $bull->upperWick(), 0.0001);
        self::assertEqualsWithDelta(5.0, $bull->lowerWick(), 0.0001);
        self::assertEqualsWithDelta(3307.5, $bull->midpoint(), 0.0001);
    }

    public function test_wicks_are_measured_from_the_body_not_the_open(): void
    {
        $bear = $this->candle('3315', '3320', '3295', '3300');

        self::assertTrue($bear->isBearish());
        self::assertEqualsWithDelta(5.0, $bear->upperWick(), 0.0001);
        self::assertEqualsWithDelta(5.0, $bear->lowerWick(), 0.0001);
    }

    public function test_a_series_sorts_oldest_first_regardless_of_input_order(): void
    {
        $series = new CandleSeries([
            $this->candle('3', '4', '2', '3', '2026-08-06 10:30:00'),
            $this->candle('1', '2', '0.5', '1', '2026-08-06 10:00:00'),
            $this->candle('2', '3', '1', '2', '2026-08-06 10:15:00'),
        ]);

        $times = array_map(
            static fn (Candle $c): string => $c->openTime->format('H:i'),
            $series->all()
        );

        self::assertSame(['10:00', '10:15', '10:30'], $times);
    }

    public function test_closed_only_filters_the_forming_bar(): void
    {
        $series = new CandleSeries([
            $this->candle('1', '2', '0.5', '1', '2026-08-06 10:00:00', closed: true),
            $this->candle('2', '3', '1', '2', '2026-08-06 10:15:00', closed: false),
        ]);

        self::assertCount(2, $series);
        self::assertCount(1, $series->closedOnly());
    }

    public function test_tail_returns_the_most_recent_bars(): void
    {
        $series = new CandleSeries([
            $this->candle('1', '2', '0.5', '1', '2026-08-06 10:00:00'),
            $this->candle('2', '3', '1', '2', '2026-08-06 10:15:00'),
            $this->candle('3', '4', '2', '3', '2026-08-06 10:30:00'),
        ]);

        $tail = $series->tail(2);

        self::assertCount(2, $tail);
        self::assertSame('10:15', $tail->first()?->openTime->format('H:i'));
        self::assertSame('10:30', $tail->last()?->openTime->format('H:i'));
    }

    /**
     * Gaps are normal for gold — weekends and the daily break. The value here
     * is telling those apart from a provider outage mid-week.
     */
    public function test_gaps_are_reported_not_thrown(): void
    {
        $series = new CandleSeries([
            $this->candle('1', '2', '0.5', '1', '2026-08-06 10:00:00'),
            // 10:15 and 10:30 missing.
            $this->candle('2', '3', '1', '2', '2026-08-06 10:45:00'),
        ]);

        $gaps = $series->gaps(15);

        self::assertCount(2, $gaps);
        self::assertSame('10:15', $gaps[0]->format('H:i'));
        self::assertSame('10:30', $gaps[1]->format('H:i'));
    }

    public function test_a_contiguous_series_reports_no_gaps(): void
    {
        $series = new CandleSeries([
            $this->candle('1', '2', '0.5', '1', '2026-08-06 10:00:00'),
            $this->candle('2', '3', '1', '2', '2026-08-06 10:15:00'),
        ]);

        self::assertSame([], $series->gaps(15));
    }

    public function test_an_empty_series_behaves(): void
    {
        $series = new CandleSeries([]);

        self::assertTrue($series->isEmpty());
        self::assertCount(0, $series);
        self::assertNull($series->first());
        self::assertNull($series->last());
        self::assertNull($series->highestHigh());
        self::assertSame([], $series->gaps(15));
    }

    public function test_extremes_across_a_series(): void
    {
        $series = new CandleSeries([
            $this->candle('3300', '3320', '3295', '3310', '2026-08-06 10:00:00'),
            $this->candle('3310', '3340', '3305', '3330', '2026-08-06 10:15:00'),
            $this->candle('3330', '3335', '3280', '3290', '2026-08-06 10:30:00'),
        ]);

        self::assertEqualsWithDelta(3340.0, $series->highestHigh(), 0.0001);
        self::assertEqualsWithDelta(3280.0, $series->lowestLow(), 0.0001);
    }

    // ── Timeframe boundary arithmetic ────────────────────────────────────────

    public function test_candle_open_floors_to_the_timeframe_boundary(): void
    {
        $m15 = new Timeframe(2, 'M15', 15, '15min');

        self::assertSame('10:15', $m15->candleOpenFor($this->utc('2026-08-06 10:22:47'))->format('H:i'));
        self::assertSame('10:15', $m15->candleOpenFor($this->utc('2026-08-06 10:15:00'))->format('H:i'));
        self::assertSame('10:00', $m15->candleOpenFor($this->utc('2026-08-06 10:14:59'))->format('H:i'));
    }

    public function test_h4_and_d1_boundaries_align_to_utc_midnight(): void
    {
        $h4 = new Timeframe(4, 'H4', 240, '4h');
        $d1 = new Timeframe(5, 'D1', 1440, '1day');

        self::assertSame('2026-08-06 08:00', $h4->candleOpenFor($this->utc('2026-08-06 11:59:00'))->format('Y-m-d H:i'));
        self::assertSame('2026-08-06 12:00', $h4->candleOpenFor($this->utc('2026-08-06 12:00:00'))->format('Y-m-d H:i'));
        self::assertSame('2026-08-06 00:00', $d1->candleOpenFor($this->utc('2026-08-06 23:59:59'))->format('Y-m-d H:i'));
    }

    public function test_is_closed_respects_the_settle_margin(): void
    {
        $m15 = new Timeframe(2, 'M15', 15, '15min');
        $open = $this->utc('2026-08-06 10:00:00');

        self::assertFalse($m15->isClosedAt($open, $this->utc('2026-08-06 10:14:59')));
        self::assertTrue($m15->isClosedAt($open, $this->utc('2026-08-06 10:15:00')));
        self::assertFalse($m15->isClosedAt($open, $this->utc('2026-08-06 10:15:10'), settleSeconds: 20));
        self::assertTrue($m15->isClosedAt($open, $this->utc('2026-08-06 10:15:20'), settleSeconds: 20));
    }
}
