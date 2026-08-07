<?php

declare(strict_types=1);

namespace GoldBot\Domain\Performance;

/**
 * Turns closed outcomes into metrics.
 *
 * ONE implementation, used by both the live Performance page and the nightly
 * snapshot builder. That is deliberate and it is the main architectural point
 * of this class: the obvious alternative — SQL aggregates for the snapshots
 * and PHP arithmetic for the live view — gives two implementations of the same
 * definitions, and they drift. The symptom is a dashboard whose Performance
 * page and Overview tile disagree about the win rate, which destroys trust in
 * both numbers and in every other number beside them.
 *
 * Pure and free of I/O (ADR-03), so every figure the platform reports can be
 * checked against a value computed by hand.
 *
 * Ordering matters: drawdown and streaks are path-dependent, so the outcomes
 * must arrive in the order they closed. sort() enforces that rather than
 * trusting the caller.
 */
final class PerformanceCalculator
{
    /** @param list<TradeOutcome> $outcomes */
    public function calculate(array $outcomes): MetricSet
    {
        if ($outcomes === []) {
            // Not zeros — an empty set. Every rate stays null, so a period with
            // no trading reads as "no data" rather than as a 0% win rate.
            return MetricSet::empty();
        }

        $outcomes = $this->sorted($outcomes);

        $wins = 0;
        $losses = 0;
        $breakeven = 0;
        $grossProfit = 0.0;
        $grossLoss = 0.0;
        $total = 0.0;

        $best = null;
        $worst = null;

        $riskRewards = [];
        $scores = [];

        foreach ($outcomes as $outcome) {
            $r = $outcome->realisedR;
            $total += $r;

            if ($outcome->isWin()) {
                $wins++;
                $grossProfit += $r;
            } elseif ($outcome->isLoss()) {
                $losses++;
                // Held as a positive magnitude: profit factor is a ratio of
                // two sizes, and a negative denominator inverts its meaning.
                $grossLoss += -$r;
            } else {
                $breakeven++;
            }

            $best = $best === null ? $r : max($best, $r);
            $worst = $worst === null ? $r : min($worst, $r);

            if ($outcome->plannedRiskReward !== null) {
                $riskRewards[] = $outcome->plannedRiskReward;
            }

            if ($outcome->score !== null) {
                $scores[] = $outcome->score;
            }
        }

        $count = count($outcomes);
        $path = $this->pathMetrics($outcomes);

        return new MetricSet(
            total: $count,
            wins: $wins,
            losses: $losses,
            breakeven: $breakeven,
            grossProfitR: $this->round($grossProfit),
            grossLossR: $this->round($grossLoss),
            totalR: $this->round($total),
            winRate: $this->percent($wins, $count),
            lossRate: $this->percent($losses, $count),
            // Undefined without losses, NOT infinite. A placeholder large
            // number would sort an untested strategy to the top of the table
            // as though it were the best one there.
            profitFactor: $grossLoss > 0.0 ? $this->round($grossProfit / $grossLoss, 2) : null,
            // The number that actually answers "is running this worth it?".
            // A 40% win rate at 3R beats a 70% win rate at 0.4R, and only
            // this figure shows it.
            expectancy: $this->round($total / $count, 3),
            averageWinR: $wins > 0 ? $this->round($grossProfit / $wins) : null,
            // Negative, matching the sign convention everywhere else.
            averageLossR: $losses > 0 ? $this->round(-$grossLoss / $losses) : null,
            averageRiskReward: $riskRewards === []
                ? null
                : $this->round(array_sum($riskRewards) / count($riskRewards)),
            averageScore: $scores === []
                ? null
                : $this->round(array_sum($scores) / count($scores), 1),
            bestR: $this->round($best),
            worstR: $this->round($worst),
            maxDrawdownR: $path['max_drawdown'],
            maxConsecutiveWins: $path['max_wins'],
            maxConsecutiveLosses: $path['max_losses'],
            currentStreak: $path['current_streak'],
        );
    }

    /**
     * Drawdown and streaks.
     *
     * These cannot be produced by a GROUP BY at all: they depend on the ORDER
     * outcomes arrived in, and grouping has discarded that by the time it
     * returns. Computing them here is not a preference — it is the only place
     * the information still exists.
     *
     * @param list<TradeOutcome> $outcomes Ordered by close time.
     * @return array{max_drawdown:float,max_wins:int,max_losses:int,current_streak:int}
     */
    private function pathMetrics(array $outcomes): array
    {
        $equity = 0.0;
        $peak = 0.0;
        $maxDrawdown = 0.0;

        $winRun = 0;
        $lossRun = 0;
        $maxWins = 0;
        $maxLosses = 0;

        foreach ($outcomes as $outcome) {
            $equity += $outcome->realisedR;

            // Peak starts at zero, so a curve that never rises above its
            // starting point still reports the full depth of its decline.
            $peak = max($peak, $equity);
            $maxDrawdown = max($maxDrawdown, $peak - $equity);

            if ($outcome->isWin()) {
                $winRun++;
                $lossRun = 0;
                $maxWins = max($maxWins, $winRun);
            } elseif ($outcome->isLoss()) {
                $lossRun++;
                $winRun = 0;
                $maxLosses = max($maxLosses, $lossRun);
            }

            // A breakeven neither extends nor breaks a run: it is not an
            // outcome in the directional sense, and treating it as a break
            // would understate genuine losing streaks.
        }

        return [
            'max_drawdown'   => $this->round($maxDrawdown),
            'max_wins'       => $maxWins,
            'max_losses'     => $maxLosses,
            // Positive is a winning run, negative a losing one, zero neither.
            'current_streak' => $winRun > 0 ? $winRun : -$lossRun,
        ];
    }

    /**
     * The cumulative R curve, one point per closed outcome.
     *
     * @param list<TradeOutcome> $outcomes
     * @return list<array{at:string,equity:float,r:float}>
     */
    public function equityCurve(array $outcomes): array
    {
        $equity = 0.0;
        $points = [];

        foreach ($this->sorted($outcomes) as $outcome) {
            $equity = $this->round($equity + $outcome->realisedR, 3);

            $points[] = [
                'at'     => $outcome->closedAt->format(DATE_ATOM),
                'equity' => $equity,
                'r'      => $this->round($outcome->realisedR, 3),
            ];
        }

        return $points;
    }

    /**
     * @param list<TradeOutcome> $outcomes
     * @return list<TradeOutcome>
     */
    private function sorted(array $outcomes): array
    {
        usort(
            $outcomes,
            static fn (TradeOutcome $a, TradeOutcome $b): int => $a->closedAt <=> $b->closedAt
        );

        return $outcomes;
    }

    private function percent(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : $this->round(($part / $whole) * 100, 1);
    }

    private function round(?float $value, int $precision = 2): ?float
    {
        return $value === null ? null : round($value, $precision);
    }
}
