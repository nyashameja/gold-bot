<?php

declare(strict_types=1);

namespace GoldBot\Domain\Indicators;

use GoldBot\Domain\Market\CandleSeries;

/**
 * Bollinger Bands: an SMA with bands at ±k standard deviations.
 *
 * Population standard deviation, not sample — this is the convention every
 * charting package uses, and the sample form (dividing by n-1) gives visibly
 * wider bands on short periods.
 */
final class BollingerBands implements IndicatorInterface
{
    public function __construct(
        private readonly int $period = 20,
        private readonly float $deviations = 2.0
    ) {
    }

    /** The middle band. Use calculateAll() for all three. */
    public function calculate(CandleSeries $series): array
    {
        return $this->calculateAll($series)['middle'];
    }

    /**
     * @return array{upper:list<float|null>,middle:list<float|null>,lower:list<float|null>}
     */
    public function calculateAll(CandleSeries $series): array
    {
        $closes = $series->closes();
        $count = count($closes);

        $middle = Sma::overValues($closes, $this->period);
        $upper = array_fill(0, $count, null);
        $lower = array_fill(0, $count, null);

        for ($i = $this->period - 1; $i < $count; $i++) {
            $mean = $middle[$i];

            if ($mean === null) {
                continue;
            }

            $window = array_slice($closes, $i - $this->period + 1, $this->period);
            $variance = 0.0;

            foreach ($window as $value) {
                $variance += ($value - $mean) ** 2;
            }

            $deviation = sqrt($variance / $this->period);

            $upper[$i] = $mean + ($this->deviations * $deviation);
            $lower[$i] = $mean - ($this->deviations * $deviation);
        }

        return ['upper' => $upper, 'middle' => $middle, 'lower' => $lower];
    }

    public function warmUpBars(): int
    {
        return $this->period;
    }

    public function name(): string
    {
        return 'bb';
    }
}
