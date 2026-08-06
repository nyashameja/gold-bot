<?php

declare(strict_types=1);

namespace GoldBot\Domain\Indicators;

use GoldBot\Domain\Market\CandleSeries;
use InvalidArgumentException;

/**
 * Average True Range, using Wilder's smoothing.
 *
 * True Range is the greatest of: the bar's own range, and the distance from
 * the previous close to this bar's high and low. Including the previous close
 * is what makes ATR account for gaps — which matters for gold, where the
 * weekend break routinely opens away from Friday's close.
 *
 * ATR is the basis for stop placement and position sizing, so its correctness
 * has direct money consequences.
 */
final class Atr implements IndicatorInterface
{
    public function __construct(private readonly int $period = 14)
    {
        if ($this->period < 1) {
            throw new InvalidArgumentException('ATR period must be at least 1.');
        }
    }

    public function calculate(CandleSeries $series): array
    {
        $candles = $series->all();
        $count = count($candles);
        $result = array_fill(0, $count, null);

        if ($count <= $this->period) {
            return $result;
        }

        $trueRanges = $this->trueRanges($series);

        // trueRanges[0] is null — there is no previous close for the first bar.
        $sum = 0.0;

        for ($i = 1; $i <= $this->period; $i++) {
            $sum += (float) $trueRanges[$i];
        }

        $atr = $sum / $this->period;
        $result[$this->period] = $atr;

        for ($i = $this->period + 1; $i < $count; $i++) {
            $atr = (($atr * ($this->period - 1)) + (float) $trueRanges[$i]) / $this->period;
            $result[$i] = $atr;
        }

        return $result;
    }

    /**
     * True Range per bar. Index 0 is null: without a previous close, the
     * gap-aware definition does not apply, and substituting the bar's own
     * range would quietly bias the first ATR.
     *
     * @return list<float|null>
     */
    public function trueRanges(CandleSeries $series): array
    {
        $candles = $series->all();
        $count = count($candles);
        $ranges = array_fill(0, $count, null);

        for ($i = 1; $i < $count; $i++) {
            $high = (float) $candles[$i]->high;
            $low = (float) $candles[$i]->low;
            $previousClose = (float) $candles[$i - 1]->close;

            $ranges[$i] = max(
                $high - $low,
                abs($high - $previousClose),
                abs($low - $previousClose)
            );
        }

        return $ranges;
    }

    public function warmUpBars(): int
    {
        return $this->period + 1;
    }

    public function name(): string
    {
        return 'atr_' . $this->period;
    }
}
