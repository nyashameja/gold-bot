<?php

declare(strict_types=1);

namespace GoldBot\Domain\Indicators;

use GoldBot\Domain\Market\CandleSeries;
use InvalidArgumentException;

/**
 * Relative Strength Index, using Wilder's smoothing.
 *
 * Wilder's original method, not a simple average of gains and losses. The
 * distinction matters: a plain SMA of gains gives visibly different values,
 * and every chart the user compares against — TradingView, MetaTrader — uses
 * Wilder's. An indicator that disagrees with the chart is worse than none.
 *
 * The first value lands at index `period`, because it needs `period` changes,
 * which needs `period + 1` closes.
 */
final class Rsi implements IndicatorInterface
{
    public function __construct(private readonly int $period = 14)
    {
        if ($this->period < 2) {
            throw new InvalidArgumentException('RSI period must be at least 2.');
        }
    }

    public function calculate(CandleSeries $series): array
    {
        $closes = $series->closes();
        $count = count($closes);
        $result = array_fill(0, $count, null);

        if ($count <= $this->period) {
            return $result;
        }

        $gainSum = 0.0;
        $lossSum = 0.0;

        for ($i = 1; $i <= $this->period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];

            if ($change >= 0) {
                $gainSum += $change;
            } else {
                $lossSum -= $change;
            }
        }

        $averageGain = $gainSum / $this->period;
        $averageLoss = $lossSum / $this->period;

        $result[$this->period] = $this->toRsi($averageGain, $averageLoss);

        for ($i = $this->period + 1; $i < $count; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain = $change > 0 ? $change : 0.0;
            $loss = $change < 0 ? -$change : 0.0;

            // Wilder's smoothing: equivalent to an EMA with alpha = 1/period.
            $averageGain = (($averageGain * ($this->period - 1)) + $gain) / $this->period;
            $averageLoss = (($averageLoss * ($this->period - 1)) + $loss) / $this->period;

            $result[$i] = $this->toRsi($averageGain, $averageLoss);
        }

        return $result;
    }

    private function toRsi(float $averageGain, float $averageLoss): float
    {
        // An unbroken run of gains gives no losses to divide by. RSI is 100 by
        // definition there; computing RS first would divide by zero.
        if ($averageLoss == 0.0) {
            return $averageGain == 0.0 ? 50.0 : 100.0;
        }

        return 100 - (100 / (1 + ($averageGain / $averageLoss)));
    }

    public function warmUpBars(): int
    {
        return $this->period + 1;
    }

    public function name(): string
    {
        return 'rsi_' . $this->period;
    }
}
