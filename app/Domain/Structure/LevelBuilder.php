<?php

declare(strict_types=1);

namespace GoldBot\Domain\Structure;

use DateTimeImmutable;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Enums\LevelType;

/**
 * Derives support, resistance and session extremes from price history.
 *
 * Support and resistance come from clustered swing points: a level touched
 * three times matters more than one touched once, so nearby swings are merged
 * and the touch count becomes the level's strength. Treating every swing as
 * its own level produces a chart covered in lines that mean nothing.
 *
 * Pure: no I/O, no clock (ADR-03). "Today" and "this week" are derived from
 * the series' own last bar, not from wall-clock time, so a backtest replaying
 * history gets the levels that existed *then*.
 */
final class LevelBuilder
{
    public function __construct(
        private readonly SwingDetector $swings,
        /** Cluster tolerance as a fraction of price — 0.001 is 10 cents at $3300. */
        private readonly float $clusterTolerance = 0.001,
        private readonly int $maxLevels = 8
    ) {
    }

    /**
     * Support and resistance, strongest first.
     *
     * @return list<PriceLevel>
     */
    public function supportAndResistance(CandleSeries $series): array
    {
        $lastClose = $series->last()?->closedAsFloat();

        if ($lastClose === null) {
            return [];
        }

        $levels = [
            ...$this->cluster($this->swings->highs($series), $lastClose),
            ...$this->cluster($this->swings->lows($series), $lastClose),
        ];

        usort($levels, static fn (PriceLevel $a, PriceLevel $b): int => $b->touchCount <=> $a->touchCount);

        return array_slice($levels, 0, $this->maxLevels);
    }

    /**
     * Supply and demand zones from sharp moves away from a base.
     *
     * A zone is the body range of the bar that preceded an impulsive move —
     * where orders were left unfilled. Impulse is measured against recent
     * average range rather than a fixed price distance, so the detector works
     * unchanged whether gold is at $1,800 or $3,300.
     *
     * @return list<PriceLevel>
     */
    public function supplyDemandZones(CandleSeries $series, float $impulseMultiplier = 2.0): array
    {
        $candles = $series->all();
        $count = count($candles);

        if ($count < 12) {
            return [];
        }

        $zones = [];

        for ($i = 6; $i < $count - 1; $i++) {
            $averageRange = $this->averageRange($candles, $i - 5, 5);

            if ($averageRange <= 0.0) {
                continue;
            }

            $next = $candles[$i + 1];

            if ($next->bodySize() < ($averageRange * $impulseMultiplier)) {
                continue;
            }

            $base = $candles[$i];
            $from = min((float) $base->open, (float) $base->close);
            $to = max((float) $base->open, (float) $base->close);

            // A degenerate base (a doji) gives a zero-width zone that would
            // match no price; widen it to the bar's full range instead.
            if ($from === $to) {
                $from = (float) $base->low;
                $to = (float) $base->high;
            }

            $zones[] = new PriceLevel(
                $next->isBullish() ? LevelType::DemandZone : LevelType::SupplyZone,
                $from,
                $to,
                $base->openTime,
                (int) min(5, max(1, round($next->bodySize() / $averageRange)))
            );
        }

        // Newest zones are the least tested and most likely to still hold.
        return array_slice(array_reverse($zones), 0, $this->maxLevels);
    }

    /**
     * Daily and weekly highs and lows, relative to the series' last bar.
     *
     * @return list<PriceLevel>
     */
    public function sessionExtremes(CandleSeries $series): array
    {
        $last = $series->last();

        if ($last === null) {
            return [];
        }

        $dayStart = $last->openTime->setTime(0, 0);
        // ISO weeks start Monday; gold's week opens Sunday evening, but the
        // Monday boundary is the convention traders quote "weekly high" by.
        $weekStart = $last->openTime->modify('monday this week')->setTime(0, 0);

        return array_values(array_filter([
            $this->extremeSince($series, $dayStart, LevelType::DailyHigh),
            $this->extremeSince($series, $dayStart, LevelType::DailyLow),
            $this->extremeSince($series, $weekStart, LevelType::WeeklyHigh),
            $this->extremeSince($series, $weekStart, LevelType::WeeklyLow),
        ]));
    }

    private function extremeSince(CandleSeries $series, DateTimeImmutable $since, LevelType $type): ?PriceLevel
    {
        $isHigh = $type === LevelType::DailyHigh || $type === LevelType::WeeklyHigh;
        $extreme = null;
        $at = null;

        foreach ($series as $candle) {
            if ($candle->openTime < $since) {
                continue;
            }

            $value = $isHigh ? (float) $candle->high : (float) $candle->low;

            if ($extreme === null || ($isHigh ? $value > $extreme : $value < $extreme)) {
                $extreme = $value;
                $at = $candle->openTime;
            }
        }

        if ($extreme === null || $at === null) {
            return null;
        }

        return new PriceLevel($type, $extreme, $extreme, $at, 3);
    }

    /**
     * Merge swings that sit within the cluster tolerance of each other.
     *
     * @param list<SwingPoint> $swings
     * @return list<PriceLevel>
     */
    private function cluster(array $swings, float $referencePrice): array
    {
        if ($swings === []) {
            return [];
        }

        $tolerance = $referencePrice * $this->clusterTolerance;

        $prices = array_map(static fn (SwingPoint $s): float => $s->price, $swings);
        sort($prices);

        /** @var list<array{prices:list<float>,at:DateTimeImmutable}> $groups */
        $groups = [];
        $current = [$prices[0]];

        for ($i = 1, $n = count($prices); $i < $n; $i++) {
            if (($prices[$i] - $current[count($current) - 1]) <= $tolerance) {
                $current[] = $prices[$i];

                continue;
            }

            $groups[] = $current;
            $current = [$prices[$i]];
        }

        $groups[] = $current;

        $levels = [];

        foreach ($groups as $group) {
            $average = array_sum($group) / count($group);

            // Which side of price it sits on decides whether it is support or
            // resistance — the same swing high becomes support once price
            // trades above it.
            $type = $average < $referencePrice ? LevelType::Support : LevelType::Resistance;

            $formedAt = null;

            foreach ($swings as $swing) {
                if (abs($swing->price - $average) <= $tolerance) {
                    $formedAt = $swing->occurredAt;
                }
            }

            $levels[] = new PriceLevel(
                $type,
                min($group),
                max($group),
                $formedAt ?? $swings[0]->occurredAt,
                (int) min(5, count($group)),
                count($group)
            );
        }

        return $levels;
    }

    /** @param list<Candle> $candles */
    private function averageRange(array $candles, int $from, int $length): float
    {
        $slice = array_slice($candles, max(0, $from), $length);

        if ($slice === []) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($slice as $candle) {
            $total += $candle->range();
        }

        return $total / count($slice);
    }
}
