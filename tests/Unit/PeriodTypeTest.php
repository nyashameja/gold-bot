<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Performance\PeriodType;
use GoldBot\Domain\Performance\SnapshotScope;
use PHPUnit\Framework\TestCase;

/**
 * Period boundaries and snapshot scoping.
 *
 * Boundaries are computed in UTC, matching every other timestamp in the
 * system. A "day" that shifted with the viewer's timezone would put the same
 * signal in different daily buckets for different users, and the daily
 * Telegram summary would disagree with the dashboard.
 */
final class PeriodTypeTest extends TestCase
{
    private function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    public function test_a_day_starts_at_midnight_utc(): void
    {
        $start = PeriodType::Daily->startFor($this->at('2026-06-15 17:42:11'));

        self::assertSame('2026-06-15 00:00:00', $start->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-16 00:00:00', PeriodType::Daily->endFor($start)->format('Y-m-d H:i:s'));
    }

    /** ISO-8601 weeks: Monday start, so a trading week is not split in two. */
    public function test_a_week_starts_on_monday(): void
    {
        // 2026-06-15 is a Monday; 2026-06-21 the Sunday that closes that week.
        foreach (['2026-06-15 00:00:00', '2026-06-18 09:00:00', '2026-06-21 23:59:59'] as $moment) {
            self::assertSame(
                '2026-06-15 00:00:00',
                PeriodType::Weekly->startFor($this->at($moment))->format('Y-m-d H:i:s'),
                $moment
            );
        }

        self::assertSame(
            '2026-06-22 00:00:00',
            PeriodType::Weekly->endFor($this->at('2026-06-15 00:00:00'))->format('Y-m-d H:i:s')
        );
    }

    public function test_a_month_starts_on_the_first(): void
    {
        $start = PeriodType::Monthly->startFor($this->at('2026-02-27 12:00:00'));

        self::assertSame('2026-02-01 00:00:00', $start->format('Y-m-d H:i:s'));
        // February's end is March's start, leap year or not.
        self::assertSame('2026-03-01 00:00:00', PeriodType::Monthly->endFor($start)->format('Y-m-d H:i:s'));
    }

    /**
     * Ends are EXCLUSIVE, so consecutive periods tile without overlap — a
     * signal closing at exactly midnight belongs to one day, not two.
     */
    public function test_consecutive_periods_tile_without_overlap(): void
    {
        $first = PeriodType::Daily->startFor($this->at('2026-06-15 12:00:00'));
        $second = PeriodType::Daily->endFor($first);

        self::assertEquals($second, PeriodType::Daily->startFor($second));
        self::assertSame(
            $first->format('Y-m-d H:i:s'),
            PeriodType::Daily->previous($second)->format('Y-m-d H:i:s')
        );
    }

    public function test_all_time_spans_everything(): void
    {
        $start = PeriodType::AllTime->startFor($this->at('2026-06-15 12:00:00'));

        self::assertLessThan($this->at('2000-01-01 00:00:00'), $start);
        self::assertGreaterThan($this->at('2090-01-01 00:00:00'), PeriodType::AllTime->endFor($start));
    }

    // ── Scope keys ───────────────────────────────────────────────────────────

    /**
     * The key exists because MySQL treats NULLs as distinct in a UNIQUE index.
     * A natural key over the nullable dimension columns alone would let the
     * same overall snapshot be stored a hundred times without complaint.
     */
    public function test_the_overall_scope_has_a_stable_key(): void
    {
        self::assertSame('*|*|*|*|*', SnapshotScope::overall()->key());
        self::assertTrue(SnapshotScope::overall()->isOverall());
    }

    public function test_scopes_with_different_dimensions_have_different_keys(): void
    {
        $keys = [
            SnapshotScope::overall()->key(),
            SnapshotScope::forStrategy(1)->key(),
            SnapshotScope::forStrategy(2)->key(),
            SnapshotScope::forSession('LONDON')->key(),
            SnapshotScope::forTimeframe(1)->key(),
            SnapshotScope::forDirection('BUY')->key(),
        ];

        self::assertSame($keys, array_unique($keys), 'Every scope must key distinctly.');
    }

    /**
     * A strategy id and a timeframe id of the same number must not collide —
     * the position in the key is what distinguishes them.
     */
    public function test_the_same_id_in_different_dimensions_does_not_collide(): void
    {
        self::assertNotSame(
            SnapshotScope::forStrategy(3)->key(),
            SnapshotScope::forTimeframe(3)->key()
        );
    }

    public function test_a_scope_round_trips_through_its_columns(): void
    {
        $scope = new SnapshotScope(
            strategyId: 4,
            instrumentId: 1,
            sessionCode: 'NEW_YORK',
            timeframeId: 2,
            direction: 'SELL'
        );

        $restored = SnapshotScope::fromColumns($scope->toColumns());

        self::assertSame($scope->key(), $restored->key());
        self::assertSame(4, $restored->strategyId);
        self::assertSame('NEW_YORK', $restored->sessionCode);
        self::assertFalse($restored->isOverall());
    }
}
