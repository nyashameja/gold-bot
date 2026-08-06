<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use DateTimeImmutable;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Repositories\Contracts\PerformanceRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;

/**
 * Performance metrics, derived from the traded record.
 *
 * The arithmetic lives here rather than in SQL for the path-dependent
 * statistics — drawdown, streaks — because they cannot be computed from
 * grouped totals at all: they depend on the ORDER outcomes arrived in, and a
 * GROUP BY has thrown that away by the time it returns.
 *
 * Everything is expressed in R, the risk multiple. A dashboard that reports
 * pips flatters wide-stop trades and punishes tight-stop ones; R is the only
 * unit under which two signals with different stop distances are comparable.
 */
final class PerformanceService
{
    public function __construct(
        private readonly PerformanceRepositoryInterface $performance,
        private readonly StrategyRepositoryInterface $strategies,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function report(int $days = 90, ?string $strategyCode = null): array
    {
        $now = $this->clock->now();
        $days = max(1, min($days, 3650));
        $since = $now->modify("-{$days} days");

        $strategyId = null;
        $strategy = null;

        if ($strategyCode !== null && $strategyCode !== '') {
            $strategy = $this->strategies->findByCode($strategyCode);
            $strategyId = $strategy === null ? null : (int) $strategy['id'];
        }

        $summary = $this->performance->summary($since, $now, $strategyId);
        $sequence = $this->performance->closedSequence($since, $now, $strategyId);

        return [
            'window' => [
                'days'  => $days,
                'since' => $since->format(DATE_ATOM),
                'until' => $now->format(DATE_ATOM),
            ],
            'strategy'   => $strategy === null ? null : (string) $strategy['code'],
            'strategies' => array_map(
                static fn (array $s): array => ['value' => (string) $s['code'], 'label' => (string) $s['name']],
                $this->strategies->enabled()
            ),
            'summary'   => $this->deriveMetrics($summary, $sequence),
            'equity'    => $this->equityCurve($sequence),
            'states'    => $this->performance->stateCounts($since, $now),
            'targets'   => $this->targetRates($since, $now),
            'bands'     => $this->performance->scoreBands($since, $now),
            'by'        => [
                'direction' => $this->withRates($this->performance->breakdown('direction', $since, $now, $strategyId)),
                'session'   => $this->withRates($this->performance->breakdown('session', $since, $now, $strategyId)),
                'timeframe' => $this->withRates($this->performance->breakdown('timeframe', $since, $now, $strategyId)),
                'hour'      => $this->withRates($this->performance->breakdown('hour', $since, $now, $strategyId)),
                'weekday'   => $this->withRates($this->performance->breakdown('weekday', $since, $now, $strategyId)),
                'month'     => $this->withRates($this->performance->breakdown('month', $since, $now, $strategyId)),
            ],
            'age' => DataAge::since($this->lastClose($sequence), $now, 86400)->toArray(),
        ];
    }

    /**
     * Headline tiles for the Overview — the same numbers, without the
     * breakdowns nobody reads on a summary page.
     *
     * @return array<string,mixed>
     */
    public function headline(int $days = 30): array
    {
        $now = $this->clock->now();
        $since = $now->modify("-{$days} days");

        return $this->deriveMetrics(
            $this->performance->summary($since, $now),
            $this->performance->closedSequence($since, $now)
        );
    }

    /**
     * @param array<string,mixed> $summary
     * @param list<array<string,mixed>> $sequence
     * @return array<string,mixed>
     */
    private function deriveMetrics(array $summary, array $sequence): array
    {
        $total = (int) $summary['total'];
        $wins = (int) $summary['wins'];
        $losses = (int) $summary['losses'];
        $grossProfit = (float) $summary['gross_profit_r'];
        $grossLoss = (float) $summary['gross_loss_r'];

        $winRate = $total === 0 ? null : round(($wins / $total) * 100, 1);

        // Expectancy: the average R per signal. The single most useful number
        // on this page, because it answers "is running this worth it?" in a
        // way win rate alone never can — a 40% win rate at 3R is excellent.
        $expectancy = $total === 0 ? null : round((float) $summary['net_r'] / $total, 3);

        // Profit factor is undefined with no losses, not infinite. Reporting
        // a placeholder large number would make an untested strategy look
        // like the best one on the page.
        $profitFactor = $grossLoss > 0.0 ? round($grossProfit / $grossLoss, 2) : null;

        return [
            'total'          => $total,
            'wins'           => $wins,
            'losses'         => $losses,
            'breakeven'      => (int) $summary['breakeven'],
            'win_rate'       => $winRate,
            'net_r'          => round((float) $summary['net_r'], 2),
            'gross_profit_r' => round($grossProfit, 2),
            'gross_loss_r'   => round($grossLoss, 2),
            'expectancy_r'   => $expectancy,
            'profit_factor'  => $profitFactor,
            'best_r'         => $summary['best_r'] === null ? null : round((float) $summary['best_r'], 2),
            'worst_r'        => $summary['worst_r'] === null ? null : round((float) $summary['worst_r'], 2),
            'avg_win_r'      => $summary['avg_win_r'] === null ? null : round((float) $summary['avg_win_r'], 2),
            'avg_loss_r'     => $summary['avg_loss_r'] === null ? null : round((float) $summary['avg_loss_r'], 2),
            'avg_score'      => $summary['avg_score'] === null ? null : round((float) $summary['avg_score'], 1),
            ...$this->pathMetrics($sequence),
        ];
    }

    /**
     * Drawdown and streaks — the statistics a grouped query cannot produce.
     *
     * Max drawdown is measured in R from the running peak of the equity
     * curve, which is the figure that tells an operator what this system has
     * actually put them through, as opposed to where it finished.
     *
     * @param list<array<string,mixed>> $sequence
     * @return array{max_drawdown_r:float,longest_win_streak:int,longest_loss_streak:int,current_streak:int}
     */
    private function pathMetrics(array $sequence): array
    {
        $equity = 0.0;
        $peak = 0.0;
        $maxDrawdown = 0.0;

        $winStreak = 0;
        $lossStreak = 0;
        $longestWin = 0;
        $longestLoss = 0;

        foreach ($sequence as $row) {
            $r = (float) $row['realised_r'];
            $equity += $r;
            $peak = max($peak, $equity);
            $maxDrawdown = max($maxDrawdown, $peak - $equity);

            if ($r > 0.0) {
                $winStreak++;
                $lossStreak = 0;
                $longestWin = max($longestWin, $winStreak);
            } elseif ($r < 0.0) {
                $lossStreak++;
                $winStreak = 0;
                $longestLoss = max($longestLoss, $lossStreak);
            } else {
                // A breakeven neither extends nor breaks a streak — it is not
                // an outcome in the direction sense.
                continue;
            }
        }

        return [
            'max_drawdown_r'      => round($maxDrawdown, 2),
            'longest_win_streak'  => $longestWin,
            'longest_loss_streak' => $longestLoss,
            // Positive is a winning run, negative a losing one.
            'current_streak'      => $winStreak > 0 ? $winStreak : -$lossStreak,
        ];
    }

    /**
     * The cumulative R curve, one point per closed signal.
     *
     * @param list<array<string,mixed>> $sequence
     * @return list<array{t:string,equity:float,r:float,uuid:string}>
     */
    private function equityCurve(array $sequence): array
    {
        $equity = 0.0;
        $points = [];

        foreach ($sequence as $row) {
            $equity = round($equity + (float) $row['realised_r'], 3);

            $points[] = [
                't'      => (string) $row['closed_at'],
                'equity' => $equity,
                'r'      => (float) $row['realised_r'],
                'uuid'   => (string) $row['uuid'],
            ];
        }

        return $points;
    }

    /**
     * @return list<array{level:int,hit:int,eligible:int,rate:float|null}>
     */
    private function targetRates(DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        return array_map(
            static function (array $row): array {
                $eligible = (int) $row['eligible'];

                return [
                    'level'    => (int) $row['level'],
                    'hit'      => (int) $row['hit'],
                    'eligible' => $eligible,
                    'rate'     => $eligible === 0 ? null : round(((int) $row['hit'] / $eligible) * 100, 1),
                ];
            },
            $this->performance->targetHitRates($since, $until)
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withRates(array $rows): array
    {
        return array_map(
            static function (array $row): array {
                $total = (int) $row['total'];

                return [
                    ...$row,
                    'net_r'    => round((float) $row['net_r'], 2),
                    'win_rate' => $total === 0 ? null : round(((int) $row['wins'] / $total) * 100, 1),
                ];
            },
            $rows
        );
    }

    /** @param list<array<string,mixed>> $sequence */
    private function lastClose(array $sequence): ?DateTimeImmutable
    {
        if ($sequence === []) {
            return null;
        }

        $last = $sequence[count($sequence) - 1];

        return new DateTimeImmutable((string) $last['closed_at'], new \DateTimeZone('UTC'));
    }
}
