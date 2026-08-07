<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Backtest\TradeOutcomeType;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Repositories\Contracts\BacktestRepositoryInterface;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
use GoldBot\Services\Backtest\BacktestRunner;
use GoldBot\Services\Backtest\ThresholdSweep;
use GoldBot\Services\MarketData\IndicatorService;
use GoldBot\Services\Signals\SignalEngine;

/**
 * The backtesting harness (ADR-04).
 *
 * The headline test is `test_the_replay_reproduces_the_live_engines_evaluations`:
 * the live engine and the backtester, given the same bar, must produce the same
 * score and direction. That single assertion is simultaneously
 *
 *   - the test of the backtester, and
 *   - the proof that ADR-03's purity guarantee actually holds,
 *
 * because the only way both can be true is if the strategy object is genuinely
 * free of hidden state and the replay is genuinely seeing the same data.
 *
 * The second thing under test is the absence of LOOKAHEAD, which is the failure
 * mode that makes a backtester worse than useless: it does not merely mislead,
 * it manufactures profitable strategies that cannot exist.
 */
final class BacktestRunnerTest extends IntegrationTestCase
{
    private BacktestRunner $runner;

    private int $instrumentId;

    private Timeframe $m15;

    private Timeframe $h1;

    private int $strategyId;

    private SettingsRepositoryInterface $settings;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('backtests')) {
            self::markTestSkipped('Backtest schema not migrated.');
        }

        $container = $this->app->container();
        $this->runner = $container->get(BacktestRunner::class);
        $this->settings = $container->get(SettingsRepositoryInterface::class);

        /** @var MarketReferenceRepositoryInterface $reference */
        $reference = $container->get(MarketReferenceRepositoryInterface::class);
        $this->instrumentId = (int) $reference->activeInstruments()[0]['id'];
        $this->m15 = $reference->timeframeByCode('M15');
        $this->h1 = $reference->timeframeByCode('H1');

        /** @var StrategyRepositoryInterface $strategies */
        $strategies = $container->get(StrategyRepositoryInterface::class);
        $strategy = $strategies->findByCode('EMA_CROSS');
        self::assertNotNull($strategy);
        $this->strategyId = (int) $strategy['id'];

        $this->clear();
        $this->seedMarket();
    }

    protected function tearDown(): void
    {
        $this->clear();

        parent::tearDown();
    }

    private function clear(): void
    {
        $this->db->run('DELETE FROM backtests');
        $this->db->run('DELETE FROM signals');
        $this->db->run('DELETE FROM strategy_runs');
        $this->db->run('DELETE FROM candles WHERE instrument_id = ?', [$this->instrumentId]);
        $this->db->run('DELETE FROM ingest_watermarks');
    }

    /**
     * A rising market with a late pullback, on both timeframes.
     *
     * Both series END together, each with its own history behind it — which is
     * what real data looks like, and what stops the higher timeframe from
     * running into the signal timeframe's future.
     */
    private function seedMarket(): void
    {
        $container = $this->app->container();
        /** @var CandleRepositoryInterface $candles */
        $candles = $container->get(CandleRepositoryInterface::class);
        /** @var IndicatorService $indicators */
        $indicators = $container->get(IndicatorService::class);

        $end = new DateTimeImmutable('2026-03-16 08:00:00', new DateTimeZone('UTC'));

        foreach ([[$this->m15, 15], [$this->h1, 60]] as [$timeframe, $minutes]) {
            $bars = [];
            $price = 3000.0;
            $start = $end->modify(sprintf('-%d minutes', 400 * $minutes));

            for ($i = 0; $i < 400; $i++) {
                $step = ($timeframe->id === $this->m15->id && $i >= 390) ? -1.2 : 1.0;

                $open = $price;
                $close = $price + $step;
                $openTime = $start->modify(sprintf('+%d minutes', $i * $minutes));

                $bars[] = new Candle(
                    $openTime,
                    $openTime->modify(sprintf('+%d minutes', $minutes - 1)),
                    number_format($open, 5, '.', ''),
                    number_format(max($open, $close) + 0.6, 5, '.', ''),
                    number_format(min($open, $close) - 0.6, 5, '.', ''),
                    number_format($close, 5, '.', ''),
                    '1000',
                    true
                );

                $price = $close;
            }

            $candles->upsertSeries($this->instrumentId, $timeframe->id, new CandleSeries($bars), 'TEST');
            $indicators->process($this->instrumentId, $timeframe);
        }
    }

    /** @return array<string,mixed> */
    private function backtest(array $options = []): array
    {
        return $this->runner->run(['strategy' => 'EMA_CROSS', 'min_score' => 55.0, ...$options]);
    }

    // ── The headline verification ────────────────────────────────────────────

    /**
     * The live engine and the replay agree, bar for bar.
     *
     * Compared at the EVALUATION level rather than at the published-signal
     * level, deliberately: the live engine additionally applies the filter
     * chain (cooldown, max-open, session, spread), and the replay does not.
     * Comparing published signals would therefore be a test of the filters.
     * The score and direction the strategy produced for a given bar is the
     * thing ADR-03 guarantees, and it is what is asserted here.
     */
    public function test_the_replay_reproduces_the_live_engines_evaluations(): void
    {
        $this->settings->set('signals.enabled', true);
        $this->db->run('UPDATE strategies SET is_enabled = 1 WHERE id = ?', [$this->strategyId]);

        try {
            /** @var SignalEngine $engine */
            $engine = $this->app->container()->get(SignalEngine::class);

            // The engine evaluates at most 200 bars per run, so it is driven
            // until it stops advancing. Otherwise it covers only the first
            // stretch of the series and the replay only the later stretch, and
            // the two never meet — the comparison would pass vacuously.
            for ($i = 0; $i < 6; $i++) {
                if ($engine->run()['evaluated'] === 0) {
                    break;
                }
            }

            $live = [];

            foreach ($this->db->select(
                'SELECT candle_open_time, score, direction FROM strategy_runs WHERE strategy_id = ?',
                [$this->strategyId]
            ) as $row) {
                $live[(string) $row['candle_open_time']] = [
                    'score'     => round((float) $row['score'], 2),
                    'direction' => $row['direction'],
                ];
            }

            self::assertNotSame([], $live, 'The live engine must have evaluated something to compare against.');

            $replay = [];

            foreach ($this->backtest()['evaluations'] as $evaluation) {
                $replay[$evaluation['candle_open_time']] = [
                    'score'     => $evaluation['score'],
                    'direction' => $evaluation['direction'],
                ];
            }

            $compared = 0;

            foreach ($live as $candleTime => $expected) {
                if (!isset($replay[$candleTime])) {
                    // Outside the backtest window; the live engine evaluates
                    // from the first stored bar, the replay skips warm-up.
                    continue;
                }

                self::assertSame(
                    $expected,
                    $replay[$candleTime],
                    "The replay disagreed with the live engine at {$candleTime}."
                );

                $compared++;
            }

            self::assertGreaterThan(
                50,
                $compared,
                'Too few overlapping bars for this comparison to mean anything.'
            );
        } finally {
            $this->db->run('UPDATE strategies SET is_enabled = 0 WHERE id = ?', [$this->strategyId]);
        }
    }

    // ── Lookahead ────────────────────────────────────────────────────────────

    /**
     * A bar's evaluation must not change when data AFTER it is added.
     *
     * This is the definitive lookahead test. Run the backtest, append more
     * candles to the end of the series, run it again over the same window: the
     * scores for the original bars must be identical. If future data can reach
     * back and alter a past evaluation, the harness reports a strategy that
     * could not have been traded.
     */
    public function test_appending_future_data_does_not_change_past_evaluations(): void
    {
        $before = $this->backtest()['evaluations'];
        self::assertNotSame([], $before);

        // Append a violent rally the original run could not have known about.
        /** @var CandleRepositoryInterface $candles */
        $candles = $this->app->container()->get(CandleRepositoryInterface::class);
        /** @var IndicatorService $indicators */
        $indicators = $this->app->container()->get(IndicatorService::class);

        foreach ([[$this->m15, 15], [$this->h1, 60]] as [$timeframe, $minutes]) {
            $last = $candles->mostRecent($this->instrumentId, $timeframe->id, closedOnly: true);
            self::assertNotNull($last);

            $price = (float) $last->close;
            $bars = [];

            for ($i = 1; $i <= 40; $i++) {
                $openTime = $last->openTime->modify(sprintf('+%d minutes', $i * $minutes));
                $open = $price;
                $close = $price + 25.0;

                $bars[] = new Candle(
                    $openTime,
                    $openTime->modify(sprintf('+%d minutes', $minutes - 1)),
                    number_format($open, 5, '.', ''),
                    number_format($close + 5, 5, '.', ''),
                    number_format($open - 5, 5, '.', ''),
                    number_format($close, 5, '.', ''),
                    '5000',
                    true
                );

                $price = $close;
            }

            $candles->upsertSeries($this->instrumentId, $timeframe->id, new CandleSeries($bars), 'TEST');
            $indicators->process($this->instrumentId, $timeframe);
        }

        $after = [];

        foreach ($this->backtest()['evaluations'] as $evaluation) {
            $after[$evaluation['candle_open_time']] = $evaluation;
        }

        $checked = 0;

        foreach ($before as $original) {
            $key = $original['candle_open_time'];

            if (!isset($after[$key])) {
                continue;
            }

            self::assertSame(
                $original,
                $after[$key],
                "Adding future data changed the evaluation at {$key} — that is lookahead bias."
            );

            $checked++;
        }

        self::assertGreaterThan(50, $checked);
    }

    /**
     * A signal generated on a bar cannot be filled by that same bar.
     *
     * The signal is produced when the bar CLOSES. Filling it from that bar's
     * own range would be trading on a price that had already passed — the
     * single most common way a backtest invents profit.
     */
    public function test_an_entry_is_never_filled_by_the_bar_that_generated_it(): void
    {
        foreach ($this->backtest()['trades'] as $trade) {
            if ($trade->activatedAt === null) {
                continue;
            }

            self::assertGreaterThan(
                $trade->signalledAt,
                $trade->activatedAt,
                'A trade was filled by the bar that generated it.'
            );
        }
    }

    // ── Simulation rules ─────────────────────────────────────────────────────

    /**
     * Where one bar covers both the stop and a target, the stop is assumed to
     * have come first — the same pessimistic rule the live tracker applies. A
     * simulation that resolved this in its own favour would report a win rate
     * the live account can never reproduce.
     */
    public function test_intra_bar_ambiguity_resolves_against_the_trade(): void
    {
        $result = $this->backtest();

        foreach ($result['trades'] as $trade) {
            if ($trade->outcome !== TradeOutcomeType::Loss) {
                continue;
            }

            // A loss exits at the stop, never at something better.
            self::assertNotNull($trade->exitPrice);
            self::assertEqualsWithDelta($trade->stopLoss, $trade->exitPrice, 0.00001);
            self::assertLessThan(0, (float) $trade->realisedR);
        }

        self::assertNotSame([], $result['trades']);
    }

    /**
     * A run that ends mid-trade says so rather than closing the position at
     * the last price, which would flatter any strategy holding a winner when
     * the data ran out.
     */
    public function test_a_trade_still_running_at_the_end_is_reported_as_open(): void
    {
        $result = $this->backtest();

        foreach ($result['trades'] as $trade) {
            if (!$trade->isOpen()) {
                continue;
            }

            self::assertNull($trade->realisedR, 'An unfinished trade has no result.');
            self::assertNull($trade->toOutcome(), 'An unfinished trade must not reach the metrics.');
        }

        self::assertSame(
            $result['still_open'],
            count(array_filter($result['trades'], static fn ($t): bool => $t->isOpen()))
        );
    }

    /** Only measurable trades reach the metrics. */
    public function test_metrics_count_only_completed_trades(): void
    {
        $result = $this->backtest();

        $measurable = count(array_filter(
            $result['trades'],
            static fn ($t): bool => $t->outcome->isMeasurable()
        ));

        self::assertSame($measurable, $result['metrics']->total);
    }

    // ── ADR-15 ───────────────────────────────────────────────────────────────

    /**
     * A news-filtered run over a period the archive does not cover is refused.
     *
     * The upstream calendar is a rolling window, so that history does not exist
     * locally and never will. Running the filter over it would apply nothing at
     * all, and the result would look like evidence that the news filter costs
     * nothing — a conclusion drawn from its absence.
     */
    public function test_a_news_filtered_run_over_an_unarchived_period_is_refused(): void
    {
        $this->db->run('DELETE FROM economic_events');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ADR-15|archived/i');

        $this->backtest(['news_filter' => true]);
    }

    // ── Sweep ────────────────────────────────────────────────────────────────

    /**
     * Raising the threshold cannot increase the number of signals. A sweep
     * that shows otherwise is re-bucketing one pass rather than re-simulating,
     * and is crediting a higher threshold with trades it could not have taken.
     */
    public function test_a_sweep_is_monotonic_in_signal_count(): void
    {
        /** @var ThresholdSweep $sweep */
        $sweep = $this->app->container()->get(ThresholdSweep::class);

        $result = $sweep->run(['strategy' => 'EMA_CROSS'], [50.0, 60.0, 70.0, 80.0, 95.0]);

        $previous = PHP_INT_MAX;

        foreach ($result['rows'] as $row) {
            self::assertLessThanOrEqual(
                $previous,
                $row['signals'],
                'A higher threshold produced more signals than a lower one.'
            );

            $previous = $row['signals'];
        }
    }

    /**
     * With a small sample the sweep declines to recommend. "Not enough data"
     * is a real answer, and dressing it up as a recommendation is how a guess
     * acquires a number and stops being questioned.
     */
    public function test_a_sweep_declines_to_recommend_on_a_thin_sample(): void
    {
        /** @var ThresholdSweep $sweep */
        $sweep = $this->app->container()->get(ThresholdSweep::class);

        $result = $sweep->run(['strategy' => 'EMA_CROSS'], [60.0, 80.0]);

        foreach ($result['rows'] as $row) {
            self::assertFalse($row['significant'], 'This fixture is deliberately small.');
        }

        self::assertNull($result['recommended']);
    }

    // ── Storage ──────────────────────────────────────────────────────────────

    public function test_a_run_is_stored_with_its_trades_and_config_snapshot(): void
    {
        /** @var BacktestRepositoryInterface $repository */
        $repository = $this->app->container()->get(BacktestRepositoryInterface::class);

        $result = $this->backtest();
        $uuid = $repository->store($result, null, 'Verification run');

        $stored = $repository->findByUuid($uuid);
        self::assertNotNull($stored);
        self::assertSame('Verification run', $stored['label']);
        self::assertSame((int) $result['metrics']->total, (int) $stored['trades_closed']);

        // The config is snapshotted, so the run stays interpretable after its
        // version is superseded.
        $snapshot = json_decode((string) $stored['config_snapshot'], true);
        self::assertIsArray($snapshot);
        self::assertArrayHasKey('pillars', $snapshot);

        self::assertCount(count($result['trades']), $repository->trades((int) $stored['id']));
    }

    /**
     * Backtest trades never touch `signals`. Every performance figure the
     * platform reports is computed from that table, and once a hypothetical
     * run is mixed in the two cannot be told apart by inspection.
     */
    public function test_a_backtest_never_writes_to_the_live_signal_record(): void
    {
        /** @var BacktestRepositoryInterface $repository */
        $repository = $this->app->container()->get(BacktestRepositoryInterface::class);

        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM signals');

        $result = $this->backtest();
        $repository->store($result);

        self::assertGreaterThan(0, count($result['trades']), 'The run must have produced trades to be meaningful.');
        self::assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM signals'));
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM signal_events'));
    }
}
