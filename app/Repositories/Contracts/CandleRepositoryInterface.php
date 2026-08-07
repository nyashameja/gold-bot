<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use DateTimeImmutable;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;

interface CandleRepositoryInterface
{
    /**
     * Insert or update a batch of bars, keyed on
     * (instrument_id, timeframe_id, open_time).
     *
     * Upsert rather than insert because vendors revise recent bars and fetch
     * windows overlap — which is what makes the whole ingest path safely
     * retryable (docs/02 §5).
     *
     * @return array{inserted:int,updated:int}
     */
    public function upsertSeries(int $instrumentId, int $timeframeId, CandleSeries $series, string $source): array;

    /** The most recent $limit bars, oldest-first. */
    /**
     * The most recent candles, oldest-first.
     *
     * $asOf bounds the series to bars that had CLOSED by that moment. Null
     * means "up to now", which is what the live engine wants. The backtester
     * passes the bar it is standing on, and that one parameter is the whole
     * defence against lookahead bias.
     */
    public function latest(
        int $instrumentId,
        int $timeframeId,
        int $limit = 300,
        bool $closedOnly = true,
        ?DateTimeImmutable $asOf = null
    ): CandleSeries;

    /** Bars whose open_time falls in [$from, $to], oldest-first. */
    public function between(
        int $instrumentId,
        int $timeframeId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        bool $closedOnly = true
    ): CandleSeries;

    /** Closed bars newer than $after, oldest-first — the incremental read. */
    public function closedSince(int $instrumentId, int $timeframeId, ?DateTimeImmutable $after, int $limit = 1000): CandleSeries;

    public function mostRecent(int $instrumentId, int $timeframeId, bool $closedOnly = true): ?Candle;

    public function count(int $instrumentId, int $timeframeId): int;

    /**
     * Mark bars that have since closed.
     *
     * A bar stored while still forming must be promoted once its window has
     * passed, or it stays invisible to every closed-only read forever.
     *
     * @return int Rows promoted.
     */
    public function markClosedBefore(int $instrumentId, int $timeframeId, DateTimeImmutable $cutoff): int;
}
