<?php

declare(strict_types=1);

namespace GoldBot\Integrations\MarketData;

use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\PriceSnapshot;
use GoldBot\Domain\Market\Timeframe;

/**
 * Market data source (docs/01 §4).
 *
 * Twelve Data is the V1 adapter. The port exists because vendor limits and
 * pricing change, and because a second source would let candles be
 * cross-validated — a single feed silently publishing a bad bar is otherwise
 * undetectable.
 */
interface MarketDataProviderInterface
{
    /**
     * Fetch the most recent bars for a symbol and timeframe.
     *
     * Returned oldest-first. Whether the newest bar has closed is decided by
     * the caller against the clock, not taken from the provider — vendors
     * differ on whether the forming bar is included (ADR-14).
     *
     * @throws MarketDataException On transport failure, an error payload, or
     *                             a response whose shape does not match.
     */
    public function candles(string $symbol, Timeframe $timeframe, int $limit = 100): CandleSeries;

    /**
     * Fetch bars covering a specific window, for backfill.
     *
     * @throws MarketDataException
     */
    public function candlesBetween(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit = 5000
    ): CandleSeries;

    /**
     * Fetch the current quote.
     *
     * @throws MarketDataException
     */
    public function quote(string $symbol): PriceSnapshot;

    /** Provider code, matching api_providers.code. */
    public function code(): string;
}
