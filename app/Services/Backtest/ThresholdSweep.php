<?php

declare(strict_types=1);

namespace GoldBot\Services\Backtest;

use GoldBot\Domain\Performance\MetricSet;
use Paragon\Core\Logging\LoggerInterface;

/**
 * Runs the same period at several score thresholds (ADR-04).
 *
 * This exists to answer the one question the whole product turns on: *is 72
 * better than 68?* Without it the threshold is picked by intuition and every
 * later tuning decision is guesswork layered on guesswork.
 *
 * ── Why it re-simulates rather than re-bucketing one pass ────────────────────
 *
 * The cheap implementation evaluates every bar once, records (score, outcome),
 * and then counts trades above each candidate threshold. It is wrong, and
 * subtly so.
 *
 * Raising the threshold does not merely remove trades — it changes WHICH
 * trades happen. The runner holds one position at a time, so a marginal signal
 * that would have been taken at 65 occupies the slot that a stronger signal
 * two bars later would otherwise have filled. Re-bucketing a single pass
 * credits the higher threshold with a trade it could never have taken, and the
 * error flatters exactly the thresholds an operator is deciding between.
 *
 * So each candidate is a full re-run. The cost is real and it is the correct
 * cost; the candle loading dominates and is per-run either way.
 *
 * ── Reading the result ───────────────────────────────────────────────────────
 *
 * The best row is not the one with the highest net R. A threshold that
 * produced four trades has not been measured, however good those four look.
 * `significant` marks the rows with enough trades to mean anything, and the
 * recommendation only ever comes from those.
 */
final class ThresholdSweep
{
    public function __construct(
        private readonly BacktestRunner $runner,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string,mixed> $options Passed to BacktestRunner::run().
     * @param list<float>         $thresholds
     * @return array<string,mixed>
     */
    public function run(array $options, array $thresholds): array
    {
        $rows = [];

        foreach ($thresholds as $threshold) {
            $result = $this->runner->run([...$options, 'min_score' => $threshold]);

            /** @var MetricSet $metrics */
            $metrics = $result['metrics'];

            $rows[] = [
                'threshold'   => $threshold,
                'signals'     => count($result['trades']),
                'closed'      => $metrics->total,
                'wins'        => $metrics->wins,
                'losses'      => $metrics->losses,
                'win_rate'    => $metrics->winRate,
                'profit_factor' => $metrics->profitFactor,
                'expectancy'  => $metrics->expectancy,
                'net_r'       => $metrics->totalR,
                'max_dd'      => $metrics->maxDrawdownR,
                'significant' => $metrics->isSignificant(),
            ];
        }

        return [
            'rows'        => $rows,
            'recommended' => $this->recommend($rows),
        ];
    }

    /**
     * The best threshold, or null when nothing was measured well enough.
     *
     * Ranked by EXPECTANCY, not by net R or win rate:
     *
     *   Net R rewards whichever threshold happened to take the most trades,
     *   which is usually the lowest one — it measures activity as much as
     *   edge. Win rate is worse still: it is maximised by a threshold so high
     *   that three trades pass, all of them lucky.
     *
     *   Expectancy is R per signal. It says what one more trade is worth,
     *   which is the actual decision.
     *
     * Returns null rather than a best guess when no row has a meaningful
     * sample. "Not enough data" is a real answer, and dressing it up as a
     * recommendation is how a guess acquires a number and stops being
     * questioned.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function recommend(array $rows): ?array
    {
        $eligible = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['significant'] === true
                && $row['expectancy'] !== null
                && $row['expectancy'] > 0
        ));

        if ($eligible === []) {
            $this->logger->info('Threshold sweep produced no recommendation', [
                'event'  => 'backtest.sweep_inconclusive',
                'reason' => 'No threshold had both a meaningful sample and a positive expectancy.',
            ]);

            return null;
        }

        usort(
            $eligible,
            static fn (array $a, array $b): int => $b['expectancy'] <=> $a['expectancy']
        );

        return $eligible[0];
    }
}
