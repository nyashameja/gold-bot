<?php

declare(strict_types=1);

namespace GoldBot\Domain\Indicators;

use GoldBot\Domain\Market\CandleSeries;

/**
 * A technical indicator.
 *
 * Every implementation is a pure function of the series (ADR-03): no database,
 * no clock, no I/O. That is what lets the whole set be tested against
 * reference values with nothing but PHP, and what lets the backtester replay
 * history through the identical code the live engine runs.
 *
 * Results are index-aligned with the input series and hold null for the
 * warm-up period — a 200-period EMA has no value at bar 5. Returning null
 * rather than a partial value matters: a strategy comparing price to a
 * half-warmed EMA would fire on a number that means nothing.
 */
interface IndicatorInterface
{
    /**
     * @return list<float|null> One entry per candle, oldest first.
     */
    public function calculate(CandleSeries $series): array;

    /** Bars required before the first non-null value. */
    public function warmUpBars(): int;

    /** Column name in candle_indicators, e.g. "ema_50". */
    public function name(): string;
}
