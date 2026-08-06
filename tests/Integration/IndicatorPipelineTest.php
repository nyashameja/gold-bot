<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Indicators\Ema;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\IndicatorRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\WatermarkRepositoryInterface;
use GoldBot\Services\MarketData\IndicatorService;
use GoldBot\Services\MarketData\StructureService;

/**
 * The incremental guarantee (ADR-14, docs/04 Phase 4).
 *
 * The subtle part is that "incremental" applies to what is *written*, not to
 * what is *computed*: an EMA-200 at bar N depends on the 200 bars before it,
 so the service must load history it does not re-store. Computing over only
 * the new bars would restart every indicator's warm-up and silently produce
 * wrong values.
 */
final class IndicatorPipelineTest extends IntegrationTestCase
{
    private CandleRepositoryInterface $candles;

    private IndicatorRepositoryInterface $indicators;

    private WatermarkRepositoryInterface $watermarks;

    private IndicatorService $service;

    private int $instrumentId;

    private Timeframe $timeframe;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('candle_indicators')) {
            self::markTestSkipped('Market data schema not migrated.');
        }

        $container = $this->app->container();
        $this->candles = $container->get(CandleRepositoryInterface::class);
        $this->indicators = $container->get(IndicatorRepositoryInterface::class);
        $this->watermarks = $container->get(WatermarkRepositoryInterface::class);
        $this->service = $container->get(IndicatorService::class);

        /** @var MarketReferenceRepositoryInterface $reference */
        $reference = $container->get(MarketReferenceRepositoryInterface::class);

        $instrument = $reference->instrumentBySymbol('XAU/USD');
        $timeframe = $reference->timeframeByCode('H1');

        self::assertNotNull($instrument);
        self::assertNotNull($timeframe);

        $this->instrumentId = $instrument['id'];
        $this->timeframe = $timeframe;

        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();

        parent::tearDown();
    }

    private function clear(): void
    {
        // candle_indicators cascades from candles.
        $this->db->run(
            'DELETE FROM candles WHERE instrument_id = ? AND timeframe_id = ?',
            [$this->instrumentId, $this->timeframe->id]
        );
        $this->db->run(
            'DELETE FROM ingest_watermarks WHERE instrument_id = ? AND timeframe_id = ?',
            [$this->instrumentId, $this->timeframe->id]
        );
        $this->db->run(
            'DELETE FROM market_levels WHERE instrument_id = ? AND timeframe_id = ?',
            [$this->instrumentId, $this->timeframe->id]
        );
        $this->db->run(
            'DELETE FROM market_structure_points WHERE instrument_id = ? AND timeframe_id = ?',
            [$this->instrumentId, $this->timeframe->id]
        );
    }

    /** Deterministic synthetic bars, appended after any already stored. */
    private function storeBars(int $count, int $startIndex = 0): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $candles = [];

        for ($i = $startIndex; $i < $startIndex + $count; $i++) {
            $open = $start->modify(sprintf('+%d hours', $i));

            // A smooth wave: deterministic, and gives indicators real variation
            // without needing a random seed shared with the assertions.
            $price = 3300 + (sin($i / 9) * 40) + ($i * 0.08);
            $high = $price + 4;
            $low = $price - 4;

            $candles[] = new Candle(
                $open,
                $open->modify('+59 minutes 59 seconds'),
                number_format($price, 5, '.', ''),
                number_format($high, 5, '.', ''),
                number_format($low, 5, '.', ''),
                number_format($price + 1, 5, '.', ''),
                '1000',
                true
            );
        }

        $this->candles->upsertSeries($this->instrumentId, $this->timeframe->id, new CandleSeries($candles), 'TEST');
    }

    public function test_indicators_are_computed_and_stored_for_new_bars(): void
    {
        $this->storeBars(300);

        $written = $this->service->process($this->instrumentId, $this->timeframe);

        self::assertSame(300, $written);
        self::assertSame(300, $this->indicators->countFor($this->instrumentId, $this->timeframe->id));

        $latest = $this->indicators->latestFor($this->instrumentId, $this->timeframe->id);

        self::assertNotNull($latest);
        self::assertNotNull($latest['ema_50']);
        self::assertNotNull($latest['ema_200'], '300 bars is enough to warm up EMA-200.');
        self::assertNotNull($latest['rsi_14']);
        self::assertNotNull($latest['atr_14']);
    }

    /**
     * The Phase 4 verification: a second pass over unchanged data must do no
     * work at all — proving incremental behaviour rather than assuming it.
     */
    public function test_a_second_run_over_unchanged_data_processes_nothing(): void
    {
        $this->storeBars(300);

        self::assertSame(300, $this->service->process($this->instrumentId, $this->timeframe));
        self::assertSame(0, $this->service->process($this->instrumentId, $this->timeframe));
        self::assertSame(0, $this->service->process($this->instrumentId, $this->timeframe));

        self::assertSame(300, $this->indicators->countFor($this->instrumentId, $this->timeframe->id));
    }

    public function test_only_the_new_bars_are_processed_on_a_later_run(): void
    {
        $this->storeBars(300);
        $this->service->process($this->instrumentId, $this->timeframe);

        $this->storeBars(5, 300);

        self::assertSame(5, $this->service->process($this->instrumentId, $this->timeframe));
        self::assertSame(305, $this->indicators->countFor($this->instrumentId, $this->timeframe->id));
    }

    /**
     * The bug this design exists to prevent. Values written incrementally must
     * be identical to values computed over the whole history in one pass — if
     * the warm-up window were dropped, the incremental EMA would restart from
     * a fresh seed and quietly diverge.
     */
    public function test_incremental_values_match_a_single_pass_computation(): void
    {
        // Incremental: 300 bars, then 5 more.
        $this->storeBars(300);
        $this->service->process($this->instrumentId, $this->timeframe);
        $this->storeBars(5, 300);
        $this->service->process($this->instrumentId, $this->timeframe);

        $incremental = $this->indicators->latestFor($this->instrumentId, $this->timeframe->id);
        self::assertNotNull($incremental);

        // Single pass: the same 305 bars, computed in one go from the domain.
        $series = $this->candles->latest($this->instrumentId, $this->timeframe->id, 1000, closedOnly: true);
        self::assertCount(305, $series);

        $expectedEma50 = (new Ema(50))->calculate($series);
        $expectedEma200 = (new Ema(200))->calculate($series);

        self::assertEqualsWithDelta(
            $expectedEma50[304],
            $incremental['ema_50'],
            1e-4,
            'Incremental EMA-50 must match a single-pass computation.'
        );

        self::assertEqualsWithDelta(
            $expectedEma200[304],
            $incremental['ema_200'],
            1e-4,
            'Incremental EMA-200 must match a single-pass computation.'
        );
    }

    /**
     * A warm-up gap must be stored as NULL, never 0.0 — a comparison against
     * zero would treat an absent EMA as a real price of nothing.
     */
    public function test_warm_up_values_are_stored_as_null_not_zero(): void
    {
        $this->storeBars(60);
        $this->service->process($this->instrumentId, $this->timeframe);

        $window = $this->indicators->window($this->instrumentId, $this->timeframe->id, 60);

        self::assertNull($window[0]['ema_50'], 'Bar 1 cannot have a 50-period EMA.');
        self::assertNull($window[0]['ema_200']);
        self::assertNotNull($window[59]['ema_50'], 'Bar 60 can.');
        self::assertNull($window[59]['ema_200'], '60 bars cannot warm up EMA-200.');
    }

    public function test_rewinding_the_watermark_reprocesses_that_stage_only(): void
    {
        $this->storeBars(300);

        // These tests store candles through the repository rather than the
        // ingest service, so the INGEST watermark must be set explicitly for
        // the stage-independence claim below to mean anything.
        $newest = $this->candles->mostRecent($this->instrumentId, $this->timeframe->id);
        self::assertNotNull($newest);

        $this->watermarks->advance(
            $this->instrumentId,
            $this->timeframe->id,
            WatermarkRepositoryInterface::STAGE_INGEST,
            $newest->openTime,
            $newest->id
        );

        $this->service->process($this->instrumentId, $this->timeframe);

        $stage = WatermarkRepositoryInterface::STAGE_INDICATORS;

        $this->watermarks->rewind(
            $this->instrumentId,
            $this->timeframe->id,
            $stage,
            new DateTimeImmutable('2026-01-11 00:00:00', new DateTimeZone('UTC'))
        );

        $reprocessed = $this->service->process($this->instrumentId, $this->timeframe);

        self::assertGreaterThan(0, $reprocessed);
        // Upsert on candle_id: reprocessing rewrites rather than duplicating.
        self::assertSame(300, $this->indicators->countFor($this->instrumentId, $this->timeframe->id));

        // The INGEST watermark is untouched — a failure or replay in one stage
        // must never force another to redo work.
        self::assertSame(
            $newest->openTime->format('Y-m-d H:i:s'),
            $this->watermarks->lastProcessed(
                $this->instrumentId,
                $this->timeframe->id,
                WatermarkRepositoryInterface::STAGE_INGEST
            )?->format('Y-m-d H:i:s')
        );
    }

    public function test_indicators_are_not_computed_for_an_unclosed_bar(): void
    {
        $this->storeBars(100);

        $start = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $open = $start->modify('+100 hours');

        $this->candles->upsertSeries(
            $this->instrumentId,
            $this->timeframe->id,
            new CandleSeries([
                new Candle($open, $open->modify('+59 minutes'), '3300', '3310', '3290', '3305', '0', false),
            ]),
            'TEST'
        );

        $this->service->process($this->instrumentId, $this->timeframe);

        self::assertSame(
            100,
            $this->indicators->countFor($this->instrumentId, $this->timeframe->id),
            'The forming bar must be excluded (ADR-14).'
        );
    }

    public function test_structure_and_levels_are_persisted_and_replaced_not_duplicated(): void
    {
        /** @var StructureService $structure */
        $structure = $this->app->container()->get(StructureService::class);

        $this->storeBars(300);

        $first = $structure->process($this->instrumentId, $this->timeframe);

        self::assertGreaterThan(0, $first['swings']);
        self::assertGreaterThan(0, $first['levels']);

        $levelsAfterFirst = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM market_levels WHERE instrument_id = ? AND timeframe_id = ?',
            [$this->instrumentId, $this->timeframe->id]
        );

        $structure->process($this->instrumentId, $this->timeframe);

        $levelsAfterSecond = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM market_levels WHERE instrument_id = ? AND timeframe_id = ?',
            [$this->instrumentId, $this->timeframe->id]
        );

        self::assertSame($levelsAfterFirst, $levelsAfterSecond, 'Levels are replaced, not appended.');

        $swings = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM market_structure_points WHERE instrument_id = ? AND timeframe_id = ?',
            [$this->instrumentId, $this->timeframe->id]
        );

        self::assertSame($first['swings'] + $first['breaks'], $swings, 'Swing upserts must not duplicate.');
    }

    public function test_a_series_too_short_for_structure_is_skipped_cleanly(): void
    {
        /** @var StructureService $structure */
        $structure = $this->app->container()->get(StructureService::class);

        $this->storeBars(10);

        self::assertSame(
            ['swings' => 0, 'breaks' => 0, 'levels' => 0],
            $structure->process($this->instrumentId, $this->timeframe)
        );
    }
}
