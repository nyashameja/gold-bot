<?php

declare(strict_types=1);

namespace GoldBot\Domain\Indicators;

use GoldBot\Domain\Market\CandleSeries;
use InvalidArgumentException;

/**
 * Moving Average Convergence Divergence.
 *
 * Produces three aligned series: the MACD line (fast EMA minus slow EMA), the
 * signal line (an EMA of the MACD line), and the histogram (line minus
 * signal).
 *
 * The signal EMA is seeded from the first `signalPeriod` *available* MACD
 * values, skipping the leading nulls. Treating those nulls as zero — an easy
 * mistake — drags the early signal line toward zero and produces spurious
 * histogram crossovers exactly where a strategy is most likely to act.
 */
final class Macd implements IndicatorInterface
{
    public function __construct(
        private readonly int $fastPeriod = 12,
        private readonly int $slowPeriod = 26,
        private readonly int $signalPeriod = 9
    ) {
        if ($this->fastPeriod >= $this->slowPeriod) {
            throw new InvalidArgumentException('MACD fast period must be shorter than the slow period.');
        }
    }

    /** The MACD line. Use calculateAll() for all three series. */
    public function calculate(CandleSeries $series): array
    {
        return $this->calculateAll($series)['macd'];
    }

    /**
     * @return array{macd:list<float|null>,signal:list<float|null>,histogram:list<float|null>}
     */
    public function calculateAll(CandleSeries $series): array
    {
        $closes = $series->closes();
        $count = count($closes);

        $fast = Ema::overValues($closes, $this->fastPeriod);
        $slow = Ema::overValues($closes, $this->slowPeriod);

        $macd = array_fill(0, $count, null);

        for ($i = 0; $i < $count; $i++) {
            if ($fast[$i] !== null && $slow[$i] !== null) {
                $macd[$i] = $fast[$i] - $slow[$i];
            }
        }

        $signal = $this->signalLine($macd);
        $histogram = array_fill(0, $count, null);

        for ($i = 0; $i < $count; $i++) {
            if ($macd[$i] !== null && $signal[$i] !== null) {
                $histogram[$i] = $macd[$i] - $signal[$i];
            }
        }

        return ['macd' => $macd, 'signal' => $signal, 'histogram' => $histogram];
    }

    /**
     * @param list<float|null> $macd
     * @return list<float|null>
     */
    private function signalLine(array $macd): array
    {
        $count = count($macd);
        $signal = array_fill(0, $count, null);

        // Compact to the defined values, remembering where they started.
        $firstDefined = null;
        $values = [];

        foreach ($macd as $index => $value) {
            if ($value === null) {
                continue;
            }

            $firstDefined ??= $index;
            $values[] = $value;
        }

        if ($firstDefined === null || count($values) < $this->signalPeriod) {
            return $signal;
        }

        $ema = Ema::overValues($values, $this->signalPeriod);

        // Map back onto the original indices.
        foreach ($ema as $offset => $value) {
            if ($value !== null) {
                $signal[$firstDefined + $offset] = $value;
            }
        }

        return $signal;
    }

    public function warmUpBars(): int
    {
        return $this->slowPeriod + $this->signalPeriod;
    }

    public function name(): string
    {
        return 'macd';
    }
}
