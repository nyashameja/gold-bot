<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Signal\SignalState;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\OperationsRepositoryInterface;
use GoldBot\Repositories\Contracts\PerformanceRepositoryInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
use GoldBot\Services\Dashboard\PerformanceService;
use GoldBot\Support\Uuid;

/**
 * The dashboard's read side, against the real schema.
 *
 * Two properties matter here and are asserted rather than assumed:
 *
 *  1. Performance counts only signals that TRADED. Cancelled and expired
 *     signals never held a position, and including them would drag the win
 *     rate toward zero and describe a strategy nobody ran.
 *  2. List pages do not issue a query per row. An N+1 does not fail a test by
 *     being wrong — it fails production by getting slower every week — so the
 *     query count is measured directly.
 */
final class DashboardReadTest extends IntegrationTestCase
{
    private PerformanceRepositoryInterface $performance;

    private OperationsRepositoryInterface $operations;

    private SignalRepositoryInterface $signals;

    private int $instrumentId;

    private int $timeframeId;

    private int $strategyId;

    private int $configId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('signals')) {
            self::markTestSkipped('Strategy schema not migrated.');
        }

        $container = $this->app->container();
        $this->performance = $container->get(PerformanceRepositoryInterface::class);
        $this->operations = $container->get(OperationsRepositoryInterface::class);
        $this->signals = $container->get(SignalRepositoryInterface::class);

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

        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();

        parent::tearDown();
    }

    private function clear(): void
    {
        $this->db->run('DELETE FROM signals');
    }

    private function base(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));
    }

    /**
     * Insert a closed signal directly. The engine's own path is covered by
     * SignalEngineTest; this fixture is about the shape of the reporting
     * queries, so it writes exactly the rows those queries read.
     */
    private function closedSignal(
        string $state,
        ?float $realisedR,
        Direction $direction = Direction::Buy,
        int $dayOffset = 0,
        float $score = 70.0,
        ?string $session = 'LONDON'
    ): int {
        $generated = $this->base()->modify("+{$dayOffset} days");
        $closed = $generated->modify('+2 hours');

        $signalId = $this->db->insert('signals', [
            'uuid'               => Uuid::toBinary(Uuid::v4()),
            'strategy_id'        => $this->strategyId,
            'strategy_config_id' => $this->configId,
            'instrument_id'      => $this->instrumentId,
            'timeframe_id'       => $this->timeframeId,
            'direction'          => $direction->value,
            'state'              => $state,
            'score'              => $score,
            'entry_price'        => '3300.00000',
            'stop_loss'          => '3297.00000',
            'risk_distance'      => '3.00000',
            'risk_reward'        => 2.0,
            'session_code'       => $session,
            'generated_at'       => $generated->format('Y-m-d H:i:s'),
            'activated_at'       => $generated->modify('+10 minutes')->format('Y-m-d H:i:s'),
            'closed_at'          => in_array($state, ['CANCELLED', 'EXPIRED'], true)
                ? null
                : $closed->format('Y-m-d H:i:s'),
            'realised_r'         => $realisedR,
        ]);

        foreach ([1, 2, 3] as $level) {
            $this->db->insert('signal_targets', [
                'signal_id' => $signalId,
                'level'     => $level,
                'price'     => number_format(3300 + ($level * 3), 5, '.', ''),
                'r_multiple' => (float) $level,
                // Only TP1 was reached, on the winners.
                'hit_at'    => ($level === 1 && $realisedR !== null && $realisedR > 0)
                    ? $closed->format('Y-m-d H:i:s')
                    : null,
            ]);
        }

        return $signalId;
    }

    // ── Traded-only scope ────────────────────────────────────────────────────

    public function test_cancelled_and_expired_signals_are_excluded_from_performance(): void
    {
        $this->closedSignal(SignalState::ClosedWin->value, 2.0);
        $this->closedSignal(SignalState::ClosedLoss->value, -1.0);
        $this->closedSignal(SignalState::Cancelled->value, null);
        $this->closedSignal(SignalState::Expired->value, null);

        $summary = $this->performance->summary(
            $this->base()->modify('-1 day'),
            $this->base()->modify('+30 days')
        );

        // Two traded, not four. A win rate of 50% rather than 25%.
        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['wins']);
        self::assertSame(1, $summary['losses']);
        self::assertEqualsWithDelta(1.0, $summary['net_r'], 0.0001);
    }

    /**
     * The untraded outcomes are still visible, counted separately — so their
     * exclusion above is something the reader can see rather than infer.
     */
    public function test_state_counts_include_the_untraded_outcomes(): void
    {
        $this->closedSignal(SignalState::ClosedWin->value, 2.0);
        $this->closedSignal(SignalState::Cancelled->value, null);
        $this->closedSignal(SignalState::Expired->value, null);

        $counts = $this->performance->stateCounts(
            $this->base()->modify('-1 day'),
            $this->base()->modify('+30 days')
        );

        self::assertSame(1, $counts['CLOSED_WIN']);
        self::assertSame(1, $counts['CANCELLED']);
        self::assertSame(1, $counts['EXPIRED']);
        // Every state is present as a key, so a view can render a full row of
        // tiles without checking for missing ones.
        self::assertArrayHasKey('BREAKEVEN', $counts);
    }

    public function test_a_breakeven_close_counts_as_traded_but_neither_won_nor_lost(): void
    {
        $this->closedSignal(SignalState::ClosedBreakeven->value, 0.0);

        $summary = $this->performance->summary(
            $this->base()->modify('-1 day'),
            $this->base()->modify('+30 days')
        );

        self::assertSame(1, $summary['total']);
        self::assertSame(0, $summary['wins']);
        self::assertSame(0, $summary['losses']);
        self::assertSame(1, $summary['breakeven']);
    }

    // ── Derived metrics ──────────────────────────────────────────────────────

    /**
     * Drawdown depends on the ORDER outcomes arrived in, which a GROUP BY has
     * thrown away by the time it returns. It is computed in PHP from the
     * sequence for exactly that reason.
     */
    public function test_max_drawdown_is_measured_from_the_running_peak(): void
    {
        // +3, then -1, -1, -1: the curve peaks at 3 and troughs at 0.
        $this->closedSignal(SignalState::ClosedWin->value, 3.0, dayOffset: 0);
        $this->closedSignal(SignalState::ClosedLoss->value, -1.0, dayOffset: 1);
        $this->closedSignal(SignalState::ClosedLoss->value, -1.0, dayOffset: 2);
        $this->closedSignal(SignalState::ClosedLoss->value, -1.0, dayOffset: 3);

        $service = $this->app->container()->get(PerformanceService::class);
        $report = $service->report(3650);

        self::assertEqualsWithDelta(0.0, $report['summary']['net_r'], 0.0001);
        self::assertEqualsWithDelta(3.0, $report['summary']['max_drawdown_r'], 0.0001);
        self::assertSame(3, $report['summary']['longest_loss_streak']);
        self::assertSame(-3, $report['summary']['current_streak']);
    }

    /**
     * Profit factor is undefined with no losses, not infinite. A placeholder
     * large number would make an untested strategy the best on the page.
     */
    public function test_profit_factor_is_null_rather_than_infinite_without_losses(): void
    {
        $this->closedSignal(SignalState::ClosedWin->value, 2.0);

        $report = $this->app->container()->get(PerformanceService::class)->report(3650);

        self::assertNull($report['summary']['profit_factor']);
        self::assertEqualsWithDelta(2.0, $report['summary']['expectancy_r'], 0.0001);
    }

    public function test_the_equity_curve_accumulates_in_order(): void
    {
        $this->closedSignal(SignalState::ClosedWin->value, 1.5, dayOffset: 0);
        $this->closedSignal(SignalState::ClosedLoss->value, -1.0, dayOffset: 1);
        $this->closedSignal(SignalState::ClosedWin->value, 2.0, dayOffset: 2);

        $report = $this->app->container()->get(PerformanceService::class)->report(3650);
        $equity = array_column($report['equity'], 'equity');

        self::assertSame([1.5, 0.5, 2.5], $equity);
    }

    public function test_breakdowns_group_by_the_requested_dimension(): void
    {
        $this->closedSignal(SignalState::ClosedWin->value, 2.0, Direction::Buy);
        $this->closedSignal(SignalState::ClosedWin->value, 1.0, Direction::Buy, dayOffset: 1);
        $this->closedSignal(SignalState::ClosedLoss->value, -1.0, Direction::Sell, dayOffset: 2);

        $rows = $this->performance->breakdown(
            'direction',
            $this->base()->modify('-1 day'),
            $this->base()->modify('+30 days')
        );

        $byBucket = array_column($rows, null, 'bucket');

        self::assertSame(2, $byBucket['BUY']['total']);
        self::assertEqualsWithDelta(3.0, $byBucket['BUY']['net_r'], 0.0001);
        self::assertSame(1, $byBucket['SELL']['total']);
    }

    /**
     * The dimension is a whitelist key, never a column name from a request —
     * this is the one place a reporting API tends to grow an injection hole.
     */
    public function test_an_unknown_dimension_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->performance->breakdown(
            'signals WHERE 1=1; DROP TABLE signals--',
            $this->base(),
            $this->base()->modify('+1 day')
        );
    }

    /**
     * Eligibility is per level. A signal with two targets must not count
     * against TP3's hit rate — a denominator that includes impossible cases
     * understates the strategy.
     */
    public function test_target_hit_rates_are_counted_per_level(): void
    {
        $this->closedSignal(SignalState::ClosedWin->value, 2.0);
        $this->closedSignal(SignalState::ClosedLoss->value, -1.0, dayOffset: 1);

        $rates = array_column(
            $this->performance->targetHitRates(
                $this->base()->modify('-1 day'),
                $this->base()->modify('+30 days')
            ),
            null,
            'level'
        );

        // Both signals had three targets; only the winner reached TP1.
        self::assertSame(2, $rates[1]['eligible']);
        self::assertSame(1, $rates[1]['hit']);
        self::assertSame(2, $rates[3]['eligible']);
        self::assertSame(0, $rates[3]['hit']);
    }

    // ── N+1 ──────────────────────────────────────────────────────────────────

    /**
     * A page of signals costs a fixed number of queries, not one per row.
     *
     * Asserted by counting statements rather than by reading the code, because
     * an N+1 is easy to reintroduce and produces no test failure of its own.
     */
    public function test_a_page_of_signals_does_not_issue_a_query_per_row(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->closedSignal(SignalState::ClosedWin->value, 1.0, dayOffset: $i);
        }

        $rows = [];
        $targets = [];

        $queries = $this->countQueries(function () use (&$rows, &$targets): void {
            $rows = $this->signals->paginate([], 25, 0);
            $targets = $this->signals->targetsFor(array_map(
                static fn (array $r): int => (int) $r['id'],
                $rows
            ));
        });

        self::assertCount(12, $rows);
        self::assertCount(12, $targets);
        // One for the page, one for every target on it.
        self::assertSame(2, $queries, 'Fetching a page and its targets must cost two queries.');

        // And the targets really are grouped by signal, not merged.
        foreach ($rows as $row) {
            self::assertCount(3, $targets[(int) $row['id']]);
        }
    }

    public function test_targets_for_an_empty_set_costs_no_query(): void
    {
        $queries = $this->countQueries(function (): void {
            self::assertSame([], $this->signals->targetsFor([]));
        });

        self::assertSame(0, $queries);
    }

    // ── Operations ───────────────────────────────────────────────────────────

    public function test_the_schedule_is_returned_with_each_task_s_latest_run(): void
    {
        $tasks = [];
        $queries = $this->countQueries(function () use (&$tasks): void {
            $tasks = $this->operations->scheduledTasks();
        });

        self::assertNotSame([], $tasks, 'The operations seed registers the scheduled tasks.');
        // The latest run is joined, not fetched per task.
        self::assertSame(1, $queries);

        foreach ($tasks as $task) {
            self::assertArrayHasKey('code', $task);
            self::assertArrayHasKey('last_status', $task);
            self::assertArrayHasKey('cadence_minutes', $task);
        }
    }

    public function test_table_sizes_are_reported_for_this_schema(): void
    {
        $tables = array_column($this->operations->tableSizes(), null, 'table_name');

        self::assertArrayHasKey('signals', $tables);
        self::assertIsInt($tables['signals']['size_bytes']);
    }

    /**
     * How many statements $work issues.
     *
     * The two SHOW STATUS probes are themselves counted by MySQL's Questions
     * counter, so the closing probe is subtracted. Measuring without that
     * correction reports every operation as one query more expensive than it
     * is — which is the sort of off-by-one that gets "fixed" by loosening the
     * assertion until it stops meaning anything.
     */
    private function countQueries(callable $work): int
    {
        $before = $this->statementCount();
        $work();

        return $this->statementCount() - $before - 1;
    }

    /** Statements MySQL has executed on this connection. */
    private function statementCount(): int
    {
        $row = $this->db->selectOne("SHOW SESSION STATUS LIKE 'Questions'");

        return (int) ($row['Value'] ?? 0);
    }
}
