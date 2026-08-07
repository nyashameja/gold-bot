<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Domain\Signal\SignalEventType;
use GoldBot\Domain\Signal\SignalState;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
use GoldBot\Services\MarketData\IndicatorService;
use GoldBot\Services\Signals\SignalEngine;

/**
 * The signal engine end to end, against the real schema.
 *
 * Exercised with EMA_CROSS rather than 714, because its rules are fully
 * specified — the pipeline can be checked against a known answer instead of a
 * placeholder (docs/00 §3, Q1).
 */
final class SignalEngineTest extends IntegrationTestCase
{
    private int $instrumentId;

    private Timeframe $m15;

    private Timeframe $h1;

    private SignalRepositoryInterface $signals;

    private StrategyRepositoryInterface $strategies;

    private SettingsRepositoryInterface $settings;

    private int $strategyId;

    /** Config versions above this id were created by the test and must go. */
    private int $baselineConfigId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('signals')) {
            self::markTestSkipped('Strategy schema not migrated.');
        }

        $container = $this->app->container();
        $this->signals = $container->get(SignalRepositoryInterface::class);
        $this->strategies = $container->get(StrategyRepositoryInterface::class);
        $this->settings = $container->get(SettingsRepositoryInterface::class);

        /** @var MarketReferenceRepositoryInterface $reference */
        $reference = $container->get(MarketReferenceRepositoryInterface::class);

        $instrument = $reference->instrumentBySymbol('XAU/USD');
        $m15 = $reference->timeframeByCode('M15');
        $h1 = $reference->timeframeByCode('H1');

        self::assertNotNull($instrument);
        self::assertNotNull($m15);
        self::assertNotNull($h1);

        $this->instrumentId = $instrument['id'];
        $this->m15 = $m15;
        $this->h1 = $h1;

        $strategy = $this->strategies->findByCode('EMA_CROSS');
        self::assertNotNull($strategy, 'EMA_CROSS must be seeded.');
        $this->strategyId = $strategy['id'];

        // Config versions are immutable and never deleted in production, so a
        // test that adds one must clean up after itself — otherwise the next
        // test inherits a tuned threshold and fails for the wrong reason.
        // Reset to the seeded version 1 first, so the baseline is known
        // regardless of what an interrupted earlier run may have left behind.
        $this->db->run('DELETE FROM strategy_configs WHERE strategy_id = ? AND version > 1', [$this->strategyId]);
        $this->db->run('UPDATE strategy_configs SET is_active = 1 WHERE strategy_id = ? AND version = 1', [$this->strategyId]);

        $this->baselineConfigId = (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM strategy_configs');

        $this->clear();

        // Enable only this strategy for the duration.
        $this->db->run('UPDATE strategies SET is_enabled = 0');
        $this->db->run('UPDATE strategies SET is_enabled = 1 WHERE id = ?', [$this->strategyId]);

        // Neutralise environmental filters so the test asserts the engine, not
        // the wall clock. Session and news are exercised separately below.
        $this->settings->set('signals.enabled', true);
        $this->settings->set('signals.sessions', []);
        $this->settings->set('news.filter_enabled', false);
        $this->settings->set('signals.cooldown_minutes', 0);
        $this->settings->set('signals.max_open', 10);
    }

    protected function tearDown(): void
    {
        $this->clear();

        $this->db->run('DELETE FROM strategy_configs WHERE id > ?', [$this->baselineConfigId]);
        $this->db->run('UPDATE strategy_configs SET is_active = 1 WHERE strategy_id = ? AND version = 1', [$this->strategyId]);

        $this->db->run('UPDATE strategies SET is_enabled = 0');
        $this->settings->set('signals.sessions', ['LONDON', 'NEW_YORK']);
        $this->settings->set('news.filter_enabled', true);
        $this->settings->set('signals.cooldown_minutes', 60);
        $this->settings->set('signals.max_open', 3);

        parent::tearDown();
    }

    private function clear(): void
    {
        // signal_targets, signal_events and signal_scores cascade.
        $this->db->run('DELETE FROM signals');
        $this->db->run('DELETE FROM strategy_runs');
        $this->db->run('DELETE FROM candles WHERE instrument_id = ?', [$this->instrumentId]);
        $this->db->run('DELETE FROM ingest_watermarks WHERE instrument_id = ?', [$this->instrumentId]);
        $this->db->run('DELETE FROM market_levels WHERE instrument_id = ?', [$this->instrumentId]);
        $this->db->run('DELETE FROM market_structure_points WHERE instrument_id = ?', [$this->instrumentId]);
    }

    /**
     * Seed a rising market with a shallow pullback at the end — the shape
     * EMA_CROSS is specified to buy.
     */
    private function seedBullishMarket(): void
    {
        /** @var CandleRepositoryInterface $candles */
        $candles = $this->app->container()->get(CandleRepositoryInterface::class);
        /** @var IndicatorService $indicators */
        $indicators = $this->app->container()->get(IndicatorService::class);

        // Both series END at the same moment, each with its own history behind
        // it. Anchoring on the START instead — which this fixture used to do —
        // left the H1 series running nine days PAST the M15 series, and every
        // M15 bar was then evaluated against H1 data from its own future. That
        // is what real data looks like too: you always hold more hours of H1
        // than of M15, not more days.
        // 08:00 UTC, so the evaluated bars land in the small hours — outside
        // both London and New York, which the session-filter test relies on.
        $end = new DateTimeImmutable('2026-01-15 08:00:00', new DateTimeZone('UTC'));

        foreach ([[$this->m15, 15], [$this->h1, 60]] as [$timeframe, $minutes]) {
            $bars = [];
            $price = 3000.0;
            $start = $end->modify(sprintf('-%d minutes', 320 * $minutes));

            for ($i = 0; $i < 320; $i++) {
                // A long steady rise, so EMA 50 sits above EMA 200 and price
                // above both, then a brief retrace to create the pullback. The
                // retrace is on the signal timeframe only: the higher-timeframe
                // trend must still read as up when the pullback is evaluated.
                $step = ($timeframe->id === $this->m15->id && $i >= 312) ? -1.2 : 1.0;

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

        // Skip the warm-up bars. In production the strategy watermark advances
        // continuously, so the engine only ever sees a handful of new candles;
        // starting from zero would exceed its 200-per-run batch and make the
        // test's "second run" assertions describe batching rather than
        // incrementality.
        /** @var \GoldBot\Repositories\Contracts\WatermarkRepositoryInterface $watermarks */
        $watermarks = $this->app->container()->get(\GoldBot\Repositories\Contracts\WatermarkRepositoryInterface::class);

        $warmUpEnd = $candles
            ->latest($this->instrumentId, $this->m15->id, 20, closedOnly: true)
            ->first();

        if ($warmUpEnd !== null) {
            $watermarks->advance(
                $this->instrumentId,
                $this->m15->id,
                \GoldBot\Repositories\Contracts\WatermarkRepositoryInterface::STAGE_STRATEGY,
                $warmUpEnd->openTime,
                $warmUpEnd->id
            );
        }
    }

    // ── Evaluation and publication ───────────────────────────────────────────

    /**
     * Every evaluation is recorded, not only the ones that fire — that is what
     * answers "why did nothing fire today?" and makes threshold tuning
     * empirical (docs/02 §7).
     */
    public function test_every_evaluation_is_recorded_even_when_nothing_publishes(): void
    {
        $this->seedBullishMarket();

        // A threshold no setup can reach.
        $this->strategies->addConfigVersion(
            $this->strategyId,
            [...$this->activeConfigArray(), 'min_score' => 999],
            'test: unreachable threshold'
        );

        $result = $this->engine()->run();

        self::assertGreaterThan(0, $result['evaluated']);
        self::assertSame(0, $result['published']);
        self::assertSame($result['evaluated'], $result['rejected']);

        $runs = (int) $this->db->scalar('SELECT COUNT(*) FROM strategy_runs WHERE strategy_id = ?', [$this->strategyId]);
        self::assertSame($result['evaluated'], $runs);

        $reason = (string) $this->db->scalar(
            'SELECT rejection_reason FROM strategy_runs WHERE strategy_id = ? ORDER BY id DESC LIMIT 1',
            [$this->strategyId]
        );

        self::assertStringStartsWith('below_threshold:', $reason);
    }

    public function test_a_qualifying_setup_publishes_a_complete_signal(): void
    {
        $this->seedBullishMarket();

        $result = $this->engine()->run();

        self::assertSame([], $result['errors']);
        self::assertGreaterThan(0, $result['published'], 'The seeded uptrend should produce a buy.');

        $signals = $this->signals->recent(10);
        self::assertNotEmpty($signals);

        $signal = $signals[0];

        self::assertSame(Direction::Buy->value, $signal['direction']);
        self::assertSame(SignalState::Pending->value, $signal['state']);
        self::assertGreaterThan(0, (float) $signal['score']);
        self::assertLessThan((float) $signal['entry_price'], (float) $signal['stop_loss'], 'A long stops below entry.');

        // Targets, pillar scores and the GENERATED event are all written in the
        // same transaction — a signal that cannot explain itself is not useful.
        self::assertCount(2, $this->signals->targets((int) $signal['id']));
        self::assertNotEmpty($this->signals->scores((int) $signal['id']));

        $events = $this->signals->events((int) $signal['id']);
        self::assertSame(SignalEventType::Generated->value, $events[0]['event_type']);
    }

    public function test_a_published_signal_is_bound_to_the_exact_config_version(): void
    {
        $this->seedBullishMarket();
        $this->engine()->run();

        $signal = $this->signals->recent(1)[0];
        $active = $this->strategies->activeConfig($this->strategyId);

        self::assertNotNull($active);
        self::assertSame($active->id, (int) $signal['strategy_config_id']);
    }

    /**
     * Re-running must not double-publish: strategy_runs is uniquely keyed by
     * candle and the watermark advances.
     */
    public function test_a_second_run_publishes_nothing_new(): void
    {
        $this->seedBullishMarket();

        $first = $this->engine()->run();
        self::assertGreaterThan(0, $first['evaluated']);

        $second = $this->engine()->run();

        self::assertSame(0, $second['evaluated'], 'Nothing new to evaluate.');
        self::assertSame(0, $second['published']);
    }

    // ── Filters ──────────────────────────────────────────────────────────────

    /**
     * The master switch stops publication but not evaluation: turning the
     * platform off must not also blind you to what it would have done.
     */
    public function test_disabling_signals_suppresses_publication_but_not_evaluation(): void
    {
        $this->seedBullishMarket();
        $this->settings->set('signals.enabled', false);

        $result = $this->engine()->run();

        self::assertGreaterThan(0, $result['evaluated']);
        self::assertSame(0, $result['published']);
        self::assertSame(0, $this->signals->countOpen());

        $reason = (string) $this->db->scalar(
            "SELECT rejection_reason FROM strategy_runs WHERE rejection_reason = 'signals_disabled' LIMIT 1"
        );

        self::assertSame('signals_disabled', $reason);
    }

    public function test_the_session_filter_suppresses_outside_allowed_sessions(): void
    {
        $this->seedBullishMarket();

        // The seeded bars run in the small hours UTC, outside both.
        $this->settings->set('signals.sessions', ['LONDON', 'NEW_YORK']);

        $result = $this->engine()->run();

        self::assertGreaterThan(0, $result['evaluated']);
        self::assertSame(0, $result['published']);

        $reasons = $this->db->select(
            "SELECT DISTINCT rejection_reason FROM strategy_runs WHERE rejection_reason LIKE 'session%'"
        );

        self::assertNotEmpty($reasons, 'Suppression must name the session, not just fail.');
    }

    public function test_the_duplicate_filter_prevents_a_second_open_signal_in_the_same_direction(): void
    {
        $this->seedBullishMarket();

        $first = $this->engine()->run();
        self::assertGreaterThan(0, $first['published']);

        $openBefore = $this->signals->countOpen();

        // Rewind the strategy watermark so the same candles are re-evaluated.
        $this->db->run(
            "DELETE FROM ingest_watermarks WHERE instrument_id = ? AND stage = 'STRATEGY'",
            [$this->instrumentId]
        );
        $this->db->run('DELETE FROM strategy_runs');

        $second = $this->engine()->run();

        self::assertSame(0, $second['published'], 'An open signal in the same direction blocks a new one.');
        self::assertSame($openBefore, $this->signals->countOpen());

        $reason = (string) $this->db->scalar(
            "SELECT rejection_reason FROM strategy_runs WHERE rejection_reason = 'duplicate_open_signal' LIMIT 1"
        );

        self::assertSame('duplicate_open_signal', $reason);
    }

    // ── Lifecycle persistence ────────────────────────────────────────────────

    public function test_events_move_the_state_projection(): void
    {
        $this->seedBullishMarket();
        $this->engine()->run();

        $signalId = (int) $this->signals->recent(1)[0]['id'];
        $at = new DateTimeImmutable('2026-01-05 12:00:00', new DateTimeZone('UTC'));

        self::assertTrue($this->signals->recordEvent($signalId, SignalEventType::EntryActivated, $at, 3300.0));
        self::assertSame(SignalState::Active->value, $this->signals->find($signalId)['state']);

        // A partial target does not close the signal.
        self::assertTrue($this->signals->recordEvent($signalId, SignalEventType::Tp1Hit, $at->modify('+1 hour'), 3310.0));
        self::assertSame(SignalState::Active->value, $this->signals->find($signalId)['state']);

        self::assertTrue($this->signals->recordEvent($signalId, SignalEventType::Tp3Hit, $at->modify('+2 hours'), 3330.0));

        $closed = $this->signals->find($signalId);
        self::assertSame(SignalState::ClosedWin->value, $closed['state']);
        self::assertNotNull($closed['closed_at']);
    }

    /**
     * The guarantee the transition table exists for: a late tick must not
     * resurrect a closed signal (ADR-05).
     */
    public function test_an_illegal_transition_is_refused(): void
    {
        $this->seedBullishMarket();
        $this->engine()->run();

        $signalId = (int) $this->signals->recent(1)[0]['id'];
        $at = new DateTimeImmutable('2026-01-05 12:00:00', new DateTimeZone('UTC'));

        $this->signals->recordEvent($signalId, SignalEventType::EntryActivated, $at);
        $this->signals->recordEvent($signalId, SignalEventType::StopLossHit, $at->modify('+1 hour'), 3290.0);

        self::assertSame(SignalState::ClosedLoss->value, $this->signals->find($signalId)['state']);

        // A stray later event must be refused, and must not append.
        $eventsBefore = count($this->signals->events($signalId));

        self::assertFalse(
            $this->signals->recordEvent($signalId, SignalEventType::Tp3Hit, $at->modify('+2 hours'), 3330.0)
        );

        self::assertSame(SignalState::ClosedLoss->value, $this->signals->find($signalId)['state']);
        self::assertCount($eventsBefore, $this->signals->events($signalId));
    }

    public function test_open_signals_are_counted_and_found_by_direction(): void
    {
        $this->seedBullishMarket();
        $this->engine()->run();

        self::assertGreaterThan(0, $this->signals->countOpen());
        self::assertTrue($this->signals->hasOpenInDirection($this->instrumentId, Direction::Buy));
        self::assertFalse($this->signals->hasOpenInDirection($this->instrumentId, Direction::Sell));
    }

    // ── Config versioning (ADR-06) ───────────────────────────────────────────

    public function test_a_new_config_version_deactivates_the_previous_one(): void
    {
        $before = $this->strategies->activeConfig($this->strategyId);
        self::assertNotNull($before);

        $newId = $this->strategies->addConfigVersion(
            $this->strategyId,
            [...$this->activeConfigArray(), 'min_score' => 80],
            'test: raised threshold'
        );

        $after = $this->strategies->activeConfig($this->strategyId);

        self::assertNotNull($after);
        self::assertSame($newId, $after->id);
        self::assertSame($before->version + 1, $after->version);
        self::assertSame(80, $after->int('min_score'));

        // The old version survives, so past signals stay attributable.
        $old = $this->strategies->configById($before->id);
        self::assertNotNull($old);
        self::assertSame($before->version, $old->version);
    }

    public function test_the_score_distribution_is_reported_for_threshold_tuning(): void
    {
        $this->seedBullishMarket();
        $this->engine()->run();

        $distribution = $this->strategies->scoreDistribution(
            $this->strategyId,
            new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC'))
        );

        self::assertNotEmpty($distribution, 'Near-misses must be visible, not just publications.');

        $total = array_sum($distribution);
        $runs = (int) $this->db->scalar('SELECT COUNT(*) FROM strategy_runs WHERE strategy_id = ?', [$this->strategyId]);

        self::assertSame($runs, $total);
    }

    /** @return array<string,mixed> */
    private function activeConfigArray(): array
    {
        $config = $this->strategies->activeConfig($this->strategyId);

        self::assertNotNull($config);

        return $config->all();
    }

    private function engine(): SignalEngine
    {
        return $this->app->container()->get(SignalEngine::class);
    }
}
