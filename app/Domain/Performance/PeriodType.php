<?php

declare(strict_types=1);

namespace GoldBot\Domain\Performance;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The rollup granularities stored in performance_snapshots (docs/02 §9).
 *
 * Boundaries are computed in UTC, matching how every other timestamp in the
 * system is stored. A "day" that shifted with the viewer's timezone would make
 * the same signal fall in different daily buckets for different users, and the
 * daily Telegram summary would disagree with the dashboard.
 */
enum PeriodType: string
{
    case Daily   = 'DAILY';
    case Weekly  = 'WEEKLY';
    case Monthly = 'MONTHLY';
    /**
     * The whole traded history, as one row. Cheap to keep alongside the
     * others and it saves the "all time" view from summing every daily row —
     * which is not merely slower but wrong, because drawdown and streaks do
     * not add up across period boundaries.
     */
    case AllTime = 'ALL_TIME';

    /** The start of the period containing $moment. */
    public function startFor(DateTimeImmutable $moment): DateTimeImmutable
    {
        $utc = $moment->setTimezone(new DateTimeZone('UTC'));

        return match ($this) {
            self::Daily   => $utc->setTime(0, 0),
            // ISO-8601 weeks: Monday start. Sunday-start weeks would split the
            // Sydney session opening from the rest of its trading week.
            self::Weekly  => $utc->modify('monday this week')->setTime(0, 0),
            self::Monthly => $utc->modify('first day of this month')->setTime(0, 0),
            self::AllTime => new DateTimeImmutable('1970-01-01 00:00:00', new DateTimeZone('UTC')),
        };
    }

    /**
     * The exclusive end of the period starting at $start.
     *
     * Exclusive so consecutive periods tile without overlap: a signal closing
     * at exactly midnight belongs to one day, not two.
     */
    public function endFor(DateTimeImmutable $start): DateTimeImmutable
    {
        return match ($this) {
            self::Daily   => $start->modify('+1 day'),
            self::Weekly  => $start->modify('+7 days'),
            self::Monthly => $start->modify('first day of next month'),
            // Far enough out that no real close time falls beyond it, without
            // reaching for a sentinel that a DATETIME column cannot hold.
            self::AllTime => new DateTimeImmutable('2099-12-31 00:00:00', new DateTimeZone('UTC')),
        };
    }

    /** The period before this one. */
    public function previous(DateTimeImmutable $start): DateTimeImmutable
    {
        return match ($this) {
            self::Daily   => $start->modify('-1 day'),
            self::Weekly  => $start->modify('-7 days'),
            self::Monthly => $start->modify('first day of last month'),
            self::AllTime => $start,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Daily   => 'Daily',
            self::Weekly  => 'Weekly',
            self::Monthly => 'Monthly',
            self::AllTime => 'All time',
        };
    }

    /** How a period is captioned once it is on screen. */
    public function format(DateTimeImmutable $start): string
    {
        return match ($this) {
            self::Daily   => $start->format('D j M Y'),
            self::Weekly  => 'Week of ' . $start->format('j M Y'),
            self::Monthly => $start->format('F Y'),
            self::AllTime => 'All time',
        };
    }

    /** The rolling periods rebuilt on a schedule. AllTime is rebuilt too. */
    public static function rollups(): array
    {
        return [self::Daily, self::Weekly, self::Monthly, self::AllTime];
    }
}
