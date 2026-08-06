<?php

declare(strict_types=1);

namespace GoldBot\Domain\Structure;

use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Enums\StructureType;
use InvalidArgumentException;

/**
 * Fractal swing detection.
 *
 * A swing high is a bar whose high exceeds the highs of the `lookback` bars on
 * both sides; a swing low is the mirror image.
 *
 * **A swing is only confirmed `lookback` bars after it forms.** That lag is
 * inherent, not a defect: you cannot know a high is a local peak until enough
 * bars have failed to exceed it. Pretending otherwise is look-ahead bias, and
 * it is the single most common way a backtest reports results the live system
 * could never have achieved (ADR-04). The detector therefore never returns a
 * swing inside the trailing `lookback` bars, even though the data is present.
 *
 * Pure: no I/O, no clock (ADR-03).
 */
final class SwingDetector
{
    public function __construct(private readonly int $lookback = 3)
    {
        if ($this->lookback < 1) {
            throw new InvalidArgumentException('Swing lookback must be at least 1.');
        }
    }

    /**
     * All confirmed swings, oldest first.
     *
     * @return list<SwingPoint>
     */
    public function detect(CandleSeries $series): array
    {
        $candles = $series->all();
        $count = count($candles);
        $swings = [];

        // Stop `lookback` bars from the end: anything later is unconfirmed.
        for ($i = $this->lookback; $i < $count - $this->lookback; $i++) {
            if ($this->isSwingHigh($candles, $i)) {
                $swings[] = new SwingPoint(
                    StructureType::SwingHigh,
                    (float) $candles[$i]->high,
                    $candles[$i]->openTime,
                    $i,
                    $this->strengthOf($candles, $i, true),
                    $candles[$i]->id
                );
            }

            if ($this->isSwingLow($candles, $i)) {
                $swings[] = new SwingPoint(
                    StructureType::SwingLow,
                    (float) $candles[$i]->low,
                    $candles[$i]->openTime,
                    $i,
                    $this->strengthOf($candles, $i, false),
                    $candles[$i]->id
                );
            }
        }

        return $swings;
    }

    /** @return list<SwingPoint> Highs only. */
    public function highs(CandleSeries $series): array
    {
        return array_values(array_filter($this->detect($series), static fn (SwingPoint $s): bool => $s->isHigh()));
    }

    /** @return list<SwingPoint> Lows only. */
    public function lows(CandleSeries $series): array
    {
        return array_values(array_filter($this->detect($series), static fn (SwingPoint $s): bool => $s->isLow()));
    }

    /** @param list<Candle> $candles */
    private function isSwingHigh(array $candles, int $index): bool
    {
        $high = (float) $candles[$index]->high;

        for ($offset = 1; $offset <= $this->lookback; $offset++) {
            // Strictly greater on the left, greater-or-equal on the right.
            // Asymmetry is deliberate: with a plateau of equal highs it picks
            // the first bar as the swing rather than emitting one per bar.
            if ((float) $candles[$index - $offset]->high >= $high) {
                return false;
            }

            if ((float) $candles[$index + $offset]->high > $high) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Candle> $candles */
    private function isSwingLow(array $candles, int $index): bool
    {
        $low = (float) $candles[$index]->low;

        for ($offset = 1; $offset <= $this->lookback; $offset++) {
            if ((float) $candles[$index - $offset]->low <= $low) {
                return false;
            }

            if ((float) $candles[$index + $offset]->low < $low) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many bars either side the swing dominates, capped at 10.
     *
     * A swing that stands out over 20 bars is a more meaningful level than one
     * that clears its immediate neighbours, and strategies weight accordingly.
     *
     * @param list<Candle> $candles
     */
    private function strengthOf(array $candles, int $index, bool $isHigh): int
    {
        $count = count($candles);
        $reference = $isHigh ? (float) $candles[$index]->high : (float) $candles[$index]->low;
        $strength = 0;

        for ($offset = 1; $offset <= 10; $offset++) {
            $left = $index - $offset;
            $right = $index + $offset;

            if ($left < 0 || $right >= $count) {
                break;
            }

            $clears = $isHigh
                ? (float) $candles[$left]->high < $reference && (float) $candles[$right]->high < $reference
                : (float) $candles[$left]->low > $reference && (float) $candles[$right]->low > $reference;

            if (!$clears) {
                break;
            }

            $strength++;
        }

        return max(1, $strength);
    }

    public function lookback(): int
    {
        return $this->lookback;
    }
}
