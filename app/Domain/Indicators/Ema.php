<?php

declare(strict_types=1);

namespace GoldBot\Domain\Indicators;

use GoldBot\Domain\Market\CandleSeries;
use InvalidArgumentException;

/**
 * Exponential moving average.
 *
 * Seeded with a simple average of the first `period` closes, then smoothed
 * with k = 2/(period+1). The SMA seed is what TradingView, MetaTrader and
 * most charting packages do; seeding with the first close instead produces
 * values that differ noticeably for the first few hundred bars, which would
 * make our EMA-200 disagree with the chart the user is looking at.
 */
final class Ema implements IndicatorInterface
{
    public function __construct(private readonly int $period)
    {
        if ($this->period < 1) {
            throw new InvalidArgumentException('EMA period must be at least 1.');
        }
    }

    public function calculate(CandleSeries $series): array
    {
        return self::overValues($series->closes(), $this->period);
    }

    /**
     * EMA over an arbitrary value series.
     *
     * Exposed because MACD needs an EMA of its own line, not of prices.
     *
     * @param list<float> $values
     * @return list<float|null>
     */
    public static function overValues(array $values, int $period): array
    {
        $count = count($values);
        $result = array_fill(0, $count, null);

        if ($count < $period) {
            return $result;
        }

        $seed = array_sum(array_slice($values, 0, $period)) / $period;
        $result[$period - 1] = $seed;

        $k = 2 / ($period + 1);
        $previous = $seed;

        for ($i = $period; $i < $count; $i++) {
            $previous = (($values[$i] - $previous) * $k) + $previous;
            $result[$i] = $previous;
        }

        return $result;
    }

    public function warmUpBars(): int
    {
        return $this->period;
    }

    public function name(): string
    {
        return 'ema_' . $this->period;
    }

    public function period(): int
    {
        return $this->period;
    }
}
