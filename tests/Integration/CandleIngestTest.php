<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\WatermarkRepositoryInterface;

/**
 * The idempotency guarantee the whole ingest path rests on (docs/02 §5).
 */
final class CandleIngestTest extends IntegrationTestCase
{
    private CandleRepositoryInterface $candles;

    private WatermarkRepositoryInterface $watermarks;

    private int $instrumentId;

    private int $timeframeId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('candles')) {
            self::markTestSkipped('Market data schema not migrated.');
        }

        $container = $this->app->container();
        $this->candles = $container->get(CandleRepositoryInterface::class);
        $this->watermarks = $container->get(WatermarkRepositoryInterface::class);

        /** @var MarketReferenceRepositoryInterface $reference */
        $reference = $container->get(MarketReferenceRepositoryInterface::class);

        $instrument = $reference->instrumentBySymbol('XAU/USD');
        $timeframe = $reference->timeframeByCode('M15');

        self::assertNotNull($instrument, 'XAU/USD must be seeded.');
        self::assertNotNull($timeframe, 'M15 must be seeded.');

        $this->instrumentId = $instrument['id'];
        $this->timeframeId = $timeframe->id;

        $this->clearCandles();
    }

    protected function tearDown(): void
    {
        $this->clearCandles();

        parent::tearDown();
    }

    private function clearCandles(): void
    {
        $this->db->run(
            'DELETE FROM candles WHERE instrument_id = ? AND timeframe_id = ?',
            [$this->instrumentId, $this->timeframeId]
        );

        $this->db->run(
            'DELETE FROM ingest_watermarks WHERE instrument_id = ? AND timeframe_id = ?',
            [$this->instrumentId, $this->timeframeId]
        );
    }

    private function utc(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }

    /** @param list<array{0:string,1:string,2:string,3:string,4:string}> $bars */
    private function series(array $bars, bool $closed = true): CandleSeries
    {
        return new CandleSeries(array_map(
            fn (array $b): Candle => new Candle(
                $this->utc($b[0]),
                $this->utc($b[0])->modify('+14 minutes 59 seconds'),
                $b[1],
                $b[2],
                $b[3],
                $b[4],
                '0',
                $closed
            ),
            $bars
        ));
    }

    /**
     * The property that makes the whole ingest path safely retryable: fetch
     * windows overlap by design, and re-importing must never duplicate a bar.
     */
    public function test_reimporting_the_same_window_creates_no_duplicates(): void
    {
        $series = $this->series([
            ['2026-08-06 10:00:00', '3300.00000', '3310.00000', '3295.00000', '3305.00000'],
            ['2026-08-06 10:15:00', '3305.00000', '3315.00000', '3300.00000', '3312.00000'],
            ['2026-08-06 10:30:00', '3312.00000', '3320.00000', '3308.00000', '3318.00000'],
        ]);

        $first = $this->candles->upsertSeries($this->instrumentId, $this->timeframeId, $series, 'TEST');
        self::assertSame(3, $first['inserted']);

        $second = $this->candles->upsertSeries($this->instrumentId, $this->timeframeId, $series, 'TEST');

        self::assertSame(0, $second['inserted'], 'A re-import must insert nothing.');
        self::assertSame(3, $second['updated']);
        self::assertSame(3, $this->candles->count($this->instrumentId, $this->timeframeId));
    }

    /**
     * Providers revise recent bars, so an overlapping fetch must update the
     * existing row rather than fail or duplicate.
     */
    public function test_a_revised_bar_updates_in_place(): void
    {
        $this->candles->upsertSeries(
            $this->instrumentId,
            $this->timeframeId,
            $this->series([['2026-08-06 10:00:00', '3300.00000', '3310.00000', '3295.00000', '3305.00000']]),
            'TEST'
        );

        // Same open_time, revised high and close.
        $this->candles->upsertSeries(
            $this->instrumentId,
            $this->timeframeId,
            $this->series([['2026-08-06 10:00:00', '3300.00000', '3325.00000', '3295.00000', '3320.00000']]),
            'TEST'
        );

        $bar = $this->candles->mostRecent($this->instrumentId, $this->timeframeId);

        self::assertSame(1, $this->candles->count($this->instrumentId, $this->timeframeId));
        self::assertSame('3325.00000', $bar?->high);
        self::assertSame('3320.00000', $bar?->close);
    }

    public function test_prices_survive_the_round_trip_exactly(): void
    {
        $this->candles->upsertSeries(
            $this->instrumentId,
            $this->timeframeId,
            $this->series([['2026-08-06 10:00:00', '3312.45000', '3315.10000', '3311.80000', '3314.20000']]),
            'TEST'
        );

        $bar = $this->candles->mostRecent($this->instrumentId, $this->timeframeId);

        self::assertSame('3312.45000', $bar?->open);
        self::assertSame('3314.20000', $bar?->close);
    }

    public function test_closed_only_reads_exclude_the_forming_bar(): void
    {
        $this->candles->upsertSeries(
            $this->instrumentId,
            $this->timeframeId,
            $this->series([['2026-08-06 10:00:00', '3300.00000', '3310.00000', '3295.00000', '3305.00000']]),
            'TEST'
        );

        $this->candles->upsertSeries(
            $this->instrumentId,
            $this->timeframeId,
            $this->series([['2026-08-06 10:15:00', '3305.00000', '3315.00000', '3300.00000', '3312.00000']], closed: false),
            'TEST'
        );

        self::assertCount(1, $this->candles->latest($this->instrumentId, $this->timeframeId, 10, closedOnly: true));
        self::assertCount(2, $this->candles->latest($this->instrumentId, $this->timeframeId, 10, closedOnly: false));
    }

    /**
     * A bar stored while forming must be promoted once its window has passed.
     * Without this it stays invisible to every closed-only read forever, and
     * the strategy engine silently skips it.
     */
    public function test_a_formerly_open_bar_is_promoted_once_it_closes(): void
    {
        $this->candles->upsertSeries(
            $this->instrumentId,
            $this->timeframeId,
            $this->series([['2026-08-06 10:00:00', '3300.00000', '3310.00000', '3295.00000', '3305.00000']], closed: false),
            'TEST'
        );

        self::assertCount(0, $this->candles->latest($this->instrumentId, $this->timeframeId, 10, closedOnly: true));

        $promoted = $this->candles->markClosedBefore(
            $this->instrumentId,
            $this->timeframeId,
            $this->utc('2026-08-06 10:20:00')
        );

        self::assertSame(1, $promoted);
        self::assertCount(1, $this->candles->latest($this->instrumentId, $this->timeframeId, 10, closedOnly: true));
    }

    public function test_latest_returns_the_newest_bars_oldest_first(): void
    {
        $bars = [];

        for ($i = 0; $i < 10; $i++) {
            $bars[] = [
                sprintf('2026-08-06 %02d:00:00', 10 + $i),
                '3300.00000', '3310.00000', '3295.00000', '3305.00000',
            ];
        }

        $this->candles->upsertSeries($this->instrumentId, $this->timeframeId, $this->series($bars), 'TEST');

        $latest = $this->candles->latest($this->instrumentId, $this->timeframeId, 3);

        self::assertCount(3, $latest);
        self::assertSame('17:00', $latest->first()?->openTime->format('H:i'));
        self::assertSame('19:00', $latest->last()?->openTime->format('H:i'));
    }

    public function test_closed_since_returns_only_newer_bars(): void
    {
        $this->candles->upsertSeries(
            $this->instrumentId,
            $this->timeframeId,
            $this->series([
                ['2026-08-06 10:00:00', '3300.00000', '3310.00000', '3295.00000', '3305.00000'],
                ['2026-08-06 10:15:00', '3305.00000', '3315.00000', '3300.00000', '3312.00000'],
                ['2026-08-06 10:30:00', '3312.00000', '3320.00000', '3308.00000', '3318.00000'],
            ]),
            'TEST'
        );

        $since = $this->candles->closedSince(
            $this->instrumentId,
            $this->timeframeId,
            $this->utc('2026-08-06 10:15:00')
        );

        self::assertCount(1, $since, 'The watermark bar itself is excluded.');
        self::assertSame('10:30', $since->first()?->openTime->format('H:i'));
    }

    // ── Watermarks ───────────────────────────────────────────────────────────

    public function test_a_watermark_advances_and_is_read_back(): void
    {
        $this->watermarks->advance(
            $this->instrumentId,
            $this->timeframeId,
            WatermarkRepositoryInterface::STAGE_INGEST,
            $this->utc('2026-08-06 10:30:00')
        );

        self::assertSame(
            '2026-08-06 10:30:00',
            $this->watermarks->lastProcessed(
                $this->instrumentId,
                $this->timeframeId,
                WatermarkRepositoryInterface::STAGE_INGEST
            )?->format('Y-m-d H:i:s')
        );
    }

    /**
     * A watermark moving backwards would silently reprocess a window — and,
     * once the signal engine exists, could re-emit signals already published.
     */
    public function test_a_watermark_never_moves_backwards(): void
    {
        $stage = WatermarkRepositoryInterface::STAGE_INGEST;

        $this->watermarks->advance($this->instrumentId, $this->timeframeId, $stage, $this->utc('2026-08-06 10:30:00'));
        $this->watermarks->advance($this->instrumentId, $this->timeframeId, $stage, $this->utc('2026-08-06 10:00:00'));

        self::assertSame(
            '2026-08-06 10:30:00',
            $this->watermarks->lastProcessed($this->instrumentId, $this->timeframeId, $stage)?->format('Y-m-d H:i:s')
        );
    }

    public function test_stages_advance_independently(): void
    {
        $this->watermarks->advance(
            $this->instrumentId,
            $this->timeframeId,
            WatermarkRepositoryInterface::STAGE_INGEST,
            $this->utc('2026-08-06 10:30:00')
        );

        // A failure in indicator computation must not force ingest to redo work.
        self::assertNull($this->watermarks->lastProcessed(
            $this->instrumentId,
            $this->timeframeId,
            WatermarkRepositoryInterface::STAGE_INDICATORS
        ));
    }

    /** Rewinding is how a single stage is replayed deliberately. */
    public function test_a_stage_can_be_rewound(): void
    {
        $stage = WatermarkRepositoryInterface::STAGE_INDICATORS;

        $this->watermarks->advance($this->instrumentId, $this->timeframeId, $stage, $this->utc('2026-08-06 10:30:00'));
        $this->watermarks->rewind($this->instrumentId, $this->timeframeId, $stage, $this->utc('2026-08-06 10:00:00'));

        self::assertSame(
            '2026-08-06 10:00:00',
            $this->watermarks->lastProcessed($this->instrumentId, $this->timeframeId, $stage)?->format('Y-m-d H:i:s')
        );

        $this->watermarks->rewind($this->instrumentId, $this->timeframeId, $stage);

        self::assertNull($this->watermarks->lastProcessed($this->instrumentId, $this->timeframeId, $stage));
    }
}
