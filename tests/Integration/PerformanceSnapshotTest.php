<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Performance\PeriodType;
use GoldBot\Domain\Performance\SnapshotScope;
use GoldBot\Domain\Signal\SignalState;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\PerformanceSnapshotRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
use GoldBot\Services\Performance\SnapshotBuilder;
use Paragon\Core\Clock\FrozenClock;
use Paragon\Core\Support\Uuid;

/**
 * The snapshot builder against the real schema.
 *
 * The property that matters most is CONVERGENCE: snapshots are a projection,
 * so a rebuild must land on the same answer no matter how many times it runs
 * or what it found. If that holds, truncating the table costs a rebuild and no
 * information — which is what makes it safe to read them as though they were
 * the record.
 */
final class PerformanceSnapshotTest extends IntegrationTestCase
{
    private PerformanceSnapshotRepositoryInterface $snapshots;

    private SnapshotBuilder $builder;

    private FrozenClock $clock;

    private int $instrumentId;

    private int $timeframeId;

    private int $strategyId;

    private int $configId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('performance_snapshots')) {
            self::markTestSkipped('Performance schema not migrated.');
        }

        $container = $this->app->container();
        $this->snapshots = $container->get(PerformanceSnapshotRepositoryInterface::class);

        /** @var MarketReferenceRepositoryInterface $reference */
        $reference = $container->get(MarketReferenceRepositoryInterface::class);
        $this->instrumentId = (int) $reference->activeInstruments()[0]['id'];
        $this->timeframeId = $reference->timeframeByCode('M15')->id;

        /** @var StrategyRepositoryInterface $strategies */
        $strategies = $container->get(StrategyRepositoryInterface::class);
        $strategy = $strategies->findByCode('EMA_CROSS');
        self::assertNotNull($strategy);
        $this->strategyId = (int) $strategy['id'];
        $this->configId = $strategies->activeConfig($this->strategyId)->id;

        $this->clock = new FrozenClock('2026-06-20 03:00:00');

        $this->builder = new SnapshotBuilder(
            $this->snapshots,
            $container->get(\GoldBot\Domain\Performance\PerformanceCalculator::class),
            $this->clock,
            $container->get(\Paragon\Core\Logging\LoggerInterface::class)
        );

        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();

        parent::tearDown();
    }

    private function clear(): void
    {
        $this->db->run('DELETE FROM performance_snapshots');
        $this->db->run('DELETE FROM signals');
    }

    /** A closed signal on a given day, with a given result. */
    private function signal(
        string $closedAt,
        ?float $realisedR,
        string $state = SignalState::ClosedWin->value,
        string $direction = 'BUY',
        ?string $session = 'LONDON'
    ): void {
        $closed = new DateTimeImmutable($closedAt, new DateTimeZone('UTC'));

        $this->db->insert('signals', [
            'uuid'               => Uuid::toBinary(Uuid::v4()),
            'strategy_id'        => $this->strategyId,
            'strategy_config_id' => $this->configId,
            'instrument_id'      => $this->instrumentId,
            'timeframe_id'       => $this->timeframeId,
            'direction'          => $direction,
            'state'              => $state,
            'score'              => 72.0,
            'entry_price'        => '3300.00000',
            'stop_loss'          => '3297.00000',
            'risk_distance'      => '3.00000',
            'risk_reward'        => 2.0,
            'session_code'       => $session,
            'generated_at'       => $closed->modify('-2 hours')->format('Y-m-d H:i:s'),
            'activated_at'       => $closed->modify('-1 hour')->format('Y-m-d H:i:s'),
            'closed_at'          => in_array($state, ['CANCELLED', 'EXPIRED'], true)
                ? null
                : $closed->format('Y-m-d H:i:s'),
            'realised_r'         => $realisedR,
        ]);
    }

    private function overall(PeriodType $period, string $start): ?array
    {
        return $this->snapshots->find(
            $period,
            new DateTimeImmutable($start, new DateTimeZone('UTC')),
            SnapshotScope::overall()
        );
    }

    // ── Convergence ──────────────────────────────────────────────────────────

    /**
     * Rebuilding is idempotent. Nothing here adjusts a running total, so
     * running it three times is indistinguishable from running it once — the
     * property that lets the nightly pass correct anything the incremental
     * refresh missed.
     */
    public function test_rebuilding_repeatedly_converges_on_the_same_answer(): void
    {
        $this->signal('2026-06-15 10:00:00', 2.0);
        $this->signal('2026-06-15 14:00:00', -1.0, SignalState::ClosedLoss->value);

        $first = $this->builder->rebuildAll();
        $countAfterFirst = $this->snapshots->count();
        $snapshotAfterFirst = $this->overall(PeriodType::Daily, '2026-06-15 00:00:00');

        $this->builder->rebuildAll();
        $this->builder->rebuildAll();

        self::assertSame($countAfterFirst, $this->snapshots->count(), 'A rebuild must not accumulate rows.');
        self::assertGreaterThan(0, $first['snapshots']);

        $snapshotAfterThird = $this->overall(PeriodType::Daily, '2026-06-15 00:00:00');

        self::assertSame(
            $snapshotAfterFirst['metrics']->toColumns(),
            $snapshotAfterThird['metrics']->toColumns()
        );
    }

    /**
     * A rebuild removes rows whose scope no longer has any signals. An orphan
     * left behind would keep asserting a result for a strategy that has been
     * deleted or a session code that was renamed.
     */
    public function test_a_rebuild_clears_snapshots_whose_signals_are_gone(): void
    {
        $this->signal('2026-06-15 10:00:00', 2.0);
        $this->builder->rebuildAll();

        self::assertGreaterThan(0, $this->snapshots->count());

        $this->db->run('DELETE FROM signals');
        $this->builder->rebuildAll();

        self::assertSame(0, $this->snapshots->count(), 'No signals means no snapshots, not stale ones.');
    }

    // ── Correctness ──────────────────────────────────────────────────────────

    /**
     * The stored figures match values computed by hand.
     *
     * Three closes on one day: +2, -1, +3.
     *   wins 2, losses 1, net +4, gross profit 5, gross loss 1
     *   win rate      = 2 ÷ 3  = 66.7%
     *   profit factor = 5 ÷ 1  = 5.00
     *   expectancy    = 4 ÷ 3  = 1.333R
     *   drawdown: equity 2, 1, 4 → peak 2, trough 1 ⇒ 1.00
     */
    public function test_a_daily_snapshot_matches_hand_calculation(): void
    {
        $this->signal('2026-06-15 09:00:00', 2.0);
        $this->signal('2026-06-15 11:00:00', -1.0, SignalState::ClosedLoss->value);
        $this->signal('2026-06-15 15:00:00', 3.0);

        $this->builder->rebuildAll();

        $snapshot = $this->overall(PeriodType::Daily, '2026-06-15 00:00:00');
        self::assertNotNull($snapshot);

        $m = $snapshot['metrics'];

        self::assertSame(3, $m->total);
        self::assertSame(2, $m->wins);
        self::assertSame(1, $m->losses);
        self::assertSame(0, $m->breakeven);
        self::assertEqualsWithDelta(66.7, $m->winRate, 0.05);
        self::assertEqualsWithDelta(5.0, $m->profitFactor, 0.001);
        self::assertEqualsWithDelta(4.0, $m->totalR, 0.001);
        self::assertEqualsWithDelta(1.333, $m->expectancy, 0.001);
        self::assertEqualsWithDelta(1.0, $m->maxDrawdownR, 0.001);
    }

    /**
     * Cancelled and expired signals never held a position. They must not
     * appear in a snapshot at all — including them would drag the win rate
     * toward zero and describe a strategy nobody ran.
     */
    public function test_untraded_signals_never_reach_a_snapshot(): void
    {
        $this->signal('2026-06-15 09:00:00', 2.0);
        $this->signal('2026-06-15 10:00:00', null, SignalState::Cancelled->value);
        $this->signal('2026-06-15 11:00:00', null, SignalState::Expired->value);

        $this->builder->rebuildAll();

        $m = $this->overall(PeriodType::Daily, '2026-06-15 00:00:00')['metrics'];

        self::assertSame(1, $m->total);
        self::assertSame(100.0, $m->winRate);
    }

    /**
     * Signals are bucketed by close time into the right day, week and month —
     * and the periods do not double-count.
     */
    public function test_a_close_lands_in_exactly_one_bucket_of_each_type(): void
    {
        // Monday 15 June and Wednesday 17 June, same week and month.
        $this->signal('2026-06-15 09:00:00', 1.0);
        $this->signal('2026-06-17 09:00:00', 1.0);
        // The following Monday: a different week, the same month.
        $this->signal('2026-06-22 09:00:00', 1.0);

        $this->builder->rebuildAll();

        self::assertSame(1, $this->overall(PeriodType::Daily, '2026-06-15 00:00:00')['metrics']->total);
        self::assertSame(1, $this->overall(PeriodType::Daily, '2026-06-17 00:00:00')['metrics']->total);

        self::assertSame(2, $this->overall(PeriodType::Weekly, '2026-06-15 00:00:00')['metrics']->total);
        self::assertSame(1, $this->overall(PeriodType::Weekly, '2026-06-22 00:00:00')['metrics']->total);

        self::assertSame(3, $this->overall(PeriodType::Monthly, '2026-06-01 00:00:00')['metrics']->total);
    }

    /**
     * A midnight close belongs to the day it starts, not the one it ends.
     * Exclusive period ends are what make that unambiguous.
     */
    public function test_a_midnight_close_belongs_to_exactly_one_day(): void
    {
        $this->signal('2026-06-16 00:00:00', 1.0);

        $this->builder->rebuildAll();

        self::assertNull($this->overall(PeriodType::Daily, '2026-06-15 00:00:00'));
        self::assertSame(1, $this->overall(PeriodType::Daily, '2026-06-16 00:00:00')['metrics']->total);
    }

    /**
     * All-time is measured over the whole history rather than summed from the
     * daily rows — because drawdown and streaks do not add up across period
     * boundaries.
     *
     * Two days: day one +3, day two -1, -1, -1. Each day's own drawdown is
     * 0 and 3; the true all-time drawdown is also 3, but a naive sum of the
     * daily figures would give 3 by luck here and be wrong in general — so the
     * assertion that matters is that all-time sees all four signals.
     */
    public function test_all_time_is_measured_over_the_whole_history(): void
    {
        $this->signal('2026-06-15 09:00:00', 3.0);
        $this->signal('2026-06-16 09:00:00', -1.0, SignalState::ClosedLoss->value);
        $this->signal('2026-06-16 10:00:00', -1.0, SignalState::ClosedLoss->value);
        $this->signal('2026-06-16 11:00:00', -1.0, SignalState::ClosedLoss->value);

        $this->builder->rebuildAll();

        $m = $this->overall(PeriodType::AllTime, '1970-01-01 00:00:00')['metrics'];

        self::assertSame(4, $m->total);
        self::assertEqualsWithDelta(0.0, $m->totalR, 0.001);
        self::assertEqualsWithDelta(3.0, $m->maxDrawdownR, 0.001);
        self::assertSame(3, $m->maxConsecutiveLosses);
    }

    // ── Scoping ──────────────────────────────────────────────────────────────

    public function test_dimension_scopes_measure_their_own_slice(): void
    {
        $this->signal('2026-06-15 09:00:00', 2.0, direction: 'BUY', session: 'LONDON');
        $this->signal('2026-06-15 10:00:00', -1.0, SignalState::ClosedLoss->value, 'SELL', 'NEW_YORK');
        $this->signal('2026-06-15 11:00:00', 1.0, direction: 'BUY', session: 'NEW_YORK');

        $this->builder->rebuildAll();

        $day = new DateTimeImmutable('2026-06-15 00:00:00', new DateTimeZone('UTC'));

        $buy = $this->snapshots->find(PeriodType::Daily, $day, SnapshotScope::forDirection('BUY'));
        self::assertSame(2, $buy['metrics']->total);
        self::assertEqualsWithDelta(3.0, $buy['metrics']->totalR, 0.001);

        $newYork = $this->snapshots->find(PeriodType::Daily, $day, SnapshotScope::forSession('NEW_YORK'));
        self::assertSame(2, $newYork['metrics']->total);
        self::assertEqualsWithDelta(0.0, $newYork['metrics']->totalR, 0.001);

        // The slices are independent, not a cross product: no snapshot exists
        // for "BUY in NEW_YORK", because the breakdowns the brief asks for are
        // separate dimensions.
        self::assertNull($this->snapshots->find(
            PeriodType::Daily,
            $day,
            new SnapshotScope(direction: 'BUY', sessionCode: 'NEW_YORK')
        ));
    }

    /**
     * An empty scope gets no row. Writing zeros for every strategy in every
     * daily bucket would fill the table with rows saying only "this did not
     * trade", which the absence of a row already says.
     */
    public function test_a_scope_with_no_signals_in_a_period_is_not_stored(): void
    {
        $this->signal('2026-06-15 09:00:00', 1.0, session: 'LONDON');

        $this->builder->rebuildAll();

        $day = new DateTimeImmutable('2026-06-15 00:00:00', new DateTimeZone('UTC'));

        self::assertNotNull($this->snapshots->find(PeriodType::Daily, $day, SnapshotScope::forSession('LONDON')));
        self::assertNull($this->snapshots->find(PeriodType::Daily, $day, SnapshotScope::forSession('TOKYO')));
    }

    // ── Incremental refresh ──────────────────────────────────────────────────

    /**
     * Closing a signal refreshes only the periods that could contain it. A
     * close on 17 June cannot change 15 June's figures.
     */
    public function test_an_incremental_refresh_touches_only_the_affected_periods(): void
    {
        $this->signal('2026-06-15 09:00:00', 1.0);
        $this->builder->rebuildAll();

        $before = $this->overall(PeriodType::Daily, '2026-06-15 00:00:00')['metrics']->toColumns();

        // A later close, refreshed incrementally rather than by full rebuild.
        $this->signal('2026-06-17 09:00:00', 2.0);
        $this->builder->rebuildFor(new DateTimeImmutable('2026-06-17 09:00:00', new DateTimeZone('UTC')));

        $after = $this->overall(PeriodType::Daily, '2026-06-15 00:00:00')['metrics']->toColumns();
        self::assertSame($before, $after, 'An unrelated day must not move.');

        // The new day exists, and the week that contains both was updated.
        self::assertSame(1, $this->overall(PeriodType::Daily, '2026-06-17 00:00:00')['metrics']->total);
        self::assertSame(2, $this->overall(PeriodType::Weekly, '2026-06-15 00:00:00')['metrics']->total);
    }

    /**
     * The incremental path and the full rebuild must agree. If they can
     * disagree, the nightly pass silently rewrites the numbers an operator
     * looked at during the day.
     */
    public function test_the_incremental_refresh_agrees_with_a_full_rebuild(): void
    {
        $this->signal('2026-06-15 09:00:00', 2.0);
        $this->signal('2026-06-15 12:00:00', -1.0, SignalState::ClosedLoss->value);
        $this->signal('2026-06-16 09:00:00', 1.5);

        foreach (['2026-06-15 09:00:00', '2026-06-15 12:00:00', '2026-06-16 09:00:00'] as $closedAt) {
            $this->builder->rebuildFor(new DateTimeImmutable($closedAt, new DateTimeZone('UTC')));
        }

        $incremental = [];

        foreach ([PeriodType::Daily, PeriodType::Weekly, PeriodType::Monthly, PeriodType::AllTime] as $period) {
            $start = $period->startFor(new DateTimeImmutable('2026-06-16 09:00:00', new DateTimeZone('UTC')));
            $found = $this->snapshots->find($period, $start, SnapshotScope::overall());
            $incremental[$period->value] = $found['metrics']->toColumns();
        }

        $this->builder->rebuildAll();

        foreach ([PeriodType::Daily, PeriodType::Weekly, PeriodType::Monthly, PeriodType::AllTime] as $period) {
            $start = $period->startFor(new DateTimeImmutable('2026-06-16 09:00:00', new DateTimeZone('UTC')));
            $found = $this->snapshots->find($period, $start, SnapshotScope::overall());

            self::assertSame(
                $incremental[$period->value],
                $found['metrics']->toColumns(),
                $period->value . ' must match between the incremental and full paths.'
            );
        }
    }

    // ── Reading back ─────────────────────────────────────────────────────────

    public function test_a_series_is_returned_oldest_first(): void
    {
        foreach (['2026-06-15', '2026-06-16', '2026-06-17'] as $day) {
            $this->signal($day . ' 09:00:00', 1.0);
        }

        $this->builder->rebuildAll();

        $series = $this->snapshots->series(PeriodType::Daily, SnapshotScope::overall(), 10);
        $starts = array_map(static fn (array $r): string => substr($r['start'], 0, 10), $series);

        self::assertSame(['2026-06-15', '2026-06-16', '2026-06-17'], $starts);
    }

    public function test_nothing_is_written_when_there_is_no_traded_history(): void
    {
        $result = $this->builder->rebuildAll();

        self::assertSame(0, $result['snapshots']);
        self::assertNull($result['from']);
        // A row of zeros would assert a measurement nobody made.
        self::assertSame(0, $this->snapshots->count());
    }
}
