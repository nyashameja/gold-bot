<?php

declare(strict_types=1);

namespace GoldBot\Services\MarketData;

use DateTimeImmutable;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Integrations\MarketData\MarketDataException;
use GoldBot\Integrations\MarketData\MarketDataProviderInterface;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\PriceSnapshotRepositoryInterface;
use GoldBot\Repositories\Contracts\WatermarkRepositoryInterface;

/**
 * Fetches bars and quotes and stores them (docs/01 §5).
 *
 * The pipeline is: request → validate → normalise → upsert → promote closed
 * bars → advance the watermark. Every step is idempotent, so a partial run
 * followed by a retry converges on the same state rather than duplicating or
 * skipping data.
 */
final class CandleIngestService
{
    public function __construct(
        private readonly MarketDataProviderInterface $provider,
        private readonly CandleRepositoryInterface $candles,
        private readonly PriceSnapshotRepositoryInterface $snapshots,
        private readonly MarketReferenceRepositoryInterface $reference,
        private readonly WatermarkRepositoryInterface $watermarks,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $pollOutputSize = 100
    ) {
    }

    /**
     * Import the latest bars for one instrument and timeframe.
     *
     * @return array{fetched:int,inserted:int,updated:int,promoted:int}
     * @throws MarketDataException
     */
    public function importLatest(int $instrumentId, Timeframe $timeframe): array
    {
        $instrument = $this->reference->instrumentById($instrumentId);

        if ($instrument === null) {
            throw new MarketDataException("Unknown instrument [{$instrumentId}].");
        }

        $series = $this->provider->candles(
            $instrument['provider_symbol'],
            $timeframe,
            $this->pollOutputSize
        );

        $result = $this->candles->upsertSeries(
            $instrumentId,
            $timeframe->id,
            $series,
            $this->provider->code()
        );

        // A bar stored while still forming must be promoted once its window
        // has passed. Without this it stays invisible to every closed-only
        // read forever, and the strategy engine silently skips it.
        $promoted = $this->candles->markClosedBefore($instrumentId, $timeframe->id, $this->clock->now());

        $this->advanceWatermark($instrumentId, $timeframe);

        $this->logger->info('Candles imported', [
            'event'      => 'market.candles_imported',
            'instrument' => $instrument['symbol'],
            'timeframe'  => $timeframe->code,
            'fetched'    => count($series),
            'inserted'   => $result['inserted'],
            'updated'    => $result['updated'],
            'promoted'   => $promoted,
        ]);

        return [
            'fetched'  => count($series),
            'inserted' => $result['inserted'],
            'updated'  => $result['updated'],
            'promoted' => $promoted,
        ];
    }

    /**
     * Seed history for one instrument and timeframe.
     *
     * Twelve Data returns up to 5000 bars per request, so a full multi-year
     * backfill across four timeframes is a handful of calls — not thousands.
     *
     * @return array{fetched:int,inserted:int,updated:int}
     */
    public function backfill(
        int $instrumentId,
        Timeframe $timeframe,
        DateTimeImmutable $from,
        ?DateTimeImmutable $to = null,
        int $batchSize = 5000
    ): array {
        $instrument = $this->reference->instrumentById($instrumentId);

        if ($instrument === null) {
            throw new MarketDataException("Unknown instrument [{$instrumentId}].");
        }

        $to ??= $this->clock->now();

        $series = $this->provider->candlesBetween(
            $instrument['provider_symbol'],
            $timeframe,
            $from,
            $to,
            $batchSize
        );

        $result = $this->candles->upsertSeries(
            $instrumentId,
            $timeframe->id,
            $series,
            $this->provider->code()
        );

        $this->candles->markClosedBefore($instrumentId, $timeframe->id, $this->clock->now());
        $this->advanceWatermark($instrumentId, $timeframe);

        $this->logger->info('Backfill complete', [
            'event'      => 'market.backfilled',
            'instrument' => $instrument['symbol'],
            'timeframe'  => $timeframe->code,
            'from'       => $from->format('c'),
            'to'         => $to->format('c'),
            'fetched'    => count($series),
            'inserted'   => $result['inserted'],
        ]);

        return [
            'fetched'  => count($series),
            'inserted' => $result['inserted'],
            'updated'  => $result['updated'],
        ];
    }

    /** Capture the current quote. */
    public function importQuote(int $instrumentId): int
    {
        $instrument = $this->reference->instrumentById($instrumentId);

        if ($instrument === null) {
            throw new MarketDataException("Unknown instrument [{$instrumentId}].");
        }

        $snapshot = $this->provider->quote($instrument['provider_symbol']);

        return $this->snapshots->store($instrumentId, $snapshot);
    }

    /**
     * The open time of the newest stored bar, closed or not.
     *
     * Used by the import task to decide whether a new bar can exist yet, so
     * a timeframe is only fetched when something has actually closed.
     */
    public function newestStoredOpenTime(int $instrumentId, Timeframe $timeframe): ?DateTimeImmutable
    {
        return $this->candles
            ->mostRecent($instrumentId, $timeframe->id, closedOnly: false)
            ?->openTime;
    }

    /**
     * Move the INGEST watermark to the newest closed bar on record.
     *
     * Read back from the database rather than taken from the fetched series:
     * if the upsert stored fewer bars than were fetched, the watermark must
     * reflect what is actually persisted, not what we hoped to persist.
     */
    private function advanceWatermark(int $instrumentId, Timeframe $timeframe): void
    {
        $newest = $this->candles->mostRecent($instrumentId, $timeframe->id, closedOnly: true);

        if ($newest === null) {
            return;
        }

        $this->watermarks->advance(
            $instrumentId,
            $timeframe->id,
            WatermarkRepositoryInterface::STAGE_INGEST,
            $newest->openTime,
            $newest->id
        );
    }
}
