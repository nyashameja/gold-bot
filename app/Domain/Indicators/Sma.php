<?php

declare(strict_types=1);

namespace GoldBot\Domain\Indicators;

use GoldBot\Domain\Market\CandleSeries;
use InvalidArgumentException;

/**
 * Simple moving average of closes.
 */
final class Sma implements IndicatorInterface
{
    public function __construct(private readonly int $period)
    {
        if ($this->period < 1) {
            throw new InvalidArgumentException('SMA period must be at least 1.');
        }
    }

    public function calculate(CandleSeries $series): array
    {
        return self::overValues($series->closes(), $this->period);
    }

    /**
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

        // Rolling sum rather than re-summing the window each step: over a
        // 200-period average across thousands of bars the difference is
        // between linear and quadratic work.
        $sum = array_sum(array_slice($values, 0, $period));
        $result[$period - 1] = $sum / $period;

        for ($i = $period; $i < $count; $i++) {
            $sum += $values[$i] - $values[$i - $period];
            $result[$i] = $sum / $period;
        }

        return $result;
    }

    public function warmUpBars(): int
    {
        return $this->period;
    }

    public function name(): string
    {
        return 'sma_' . $this->period;
    }
}
