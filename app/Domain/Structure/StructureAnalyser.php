<?php

declare(strict_types=1);

namespace GoldBot\Domain\Structure;

use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Enums\StructureType;
use GoldBot\Domain\Market\Enums\TrendState;

/**
 * Reads trend and structural breaks from swing points.
 *
 * Trend is defined structurally — higher highs with higher lows is an uptrend,
 * lower highs with lower lows a downtrend — rather than by a moving-average
 * slope. Structure is what price actually did; an MA is a lagging derivative
 * of it, and using one to define trend means the definition changes whenever
 * the period is retuned.
 *
 * Two events are distinguished, and the difference matters to a strategy:
 *
 * - **BOS** (break of structure): price takes out the prior swing in the
 *   direction of the trend. Continuation.
 * - **CHoCH** (change of character): price takes out the prior swing *against*
 *   the trend. The first warning the trend may be over.
 *
 * Pure: no I/O, no clock (ADR-03).
 */
final class StructureAnalyser
{
    public function __construct(private readonly SwingDetector $swings)
    {
    }

    /**
     * The current trend, from the last two confirmed highs and lows.
     */
    public function trend(CandleSeries $series): TrendState
    {
        $highs = $this->swings->highs($series);
        $lows = $this->swings->lows($series);

        if (count($highs) < 2 || count($lows) < 2) {
            // Not enough structure to make a claim. Distinct from Ranging,
            // which asserts the market *is* directionless.
            return TrendState::Unknown;
        }

        $lastHigh = $highs[count($highs) - 1]->price;
        $priorHigh = $highs[count($highs) - 2]->price;
        $lastLow = $lows[count($lows) - 1]->price;
        $priorLow = $lows[count($lows) - 2]->price;

        $higherHighs = $lastHigh > $priorHigh;
        $higherLows = $lastLow > $priorLow;

        if ($higherHighs && $higherLows) {
            return TrendState::Uptrend;
        }

        if (!$higherHighs && !$higherLows) {
            return TrendState::Downtrend;
        }

        // Mixed — an expanding or contracting range, not a trend.
        return TrendState::Ranging;
    }

    /**
     * Structural breaks, oldest first.
     *
     * @return list<StructureBreak>
     */
    public function breaks(CandleSeries $series): array
    {
        $swings = $this->swings->detect($series);

        if (count($swings) < 3) {
            return [];
        }

        $candles = $series->all();
        $breaks = [];

        $trend = TrendState::Unknown;
        $lastHigh = null;
        $lastLow = null;

        foreach ($swings as $swing) {
            if ($swing->type === StructureType::SwingHigh) {
                if ($lastHigh !== null && $swing->price > $lastHigh) {
                    // Broke the prior high. Continuation if already up,
                    // a change of character if the trend was down.
                    $isChoch = $trend === TrendState::Downtrend;

                    $breaks[] = new StructureBreak(
                        $isChoch ? StructureType::Choch : StructureType::Bos,
                        $swing->price,
                        $swing->occurredAt,
                        $swing->index,
                        TrendState::Uptrend,
                        $candles[$swing->index]->id ?? null
                    );

                    $trend = TrendState::Uptrend;
                }

                $lastHigh = $swing->price;

                continue;
            }

            if ($lastLow !== null && $swing->price < $lastLow) {
                $isChoch = $trend === TrendState::Uptrend;

                $breaks[] = new StructureBreak(
                    $isChoch ? StructureType::Choch : StructureType::Bos,
                    $swing->price,
                    $swing->occurredAt,
                    $swing->index,
                    TrendState::Downtrend,
                    $candles[$swing->index]->id ?? null
                );

                $trend = TrendState::Downtrend;
            }

            $lastLow = $swing->price;
        }

        return $breaks;
    }

    /** The most recent structural break, if any. */
    public function lastBreak(CandleSeries $series): ?StructureBreak
    {
        $breaks = $this->breaks($series);

        return $breaks === [] ? null : $breaks[count($breaks) - 1];
    }
}
