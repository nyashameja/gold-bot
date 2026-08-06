<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use DateTimeImmutable;

/**
 * Aggregate reads over closed signals (docs/02 §8).
 *
 * Only signals that actually traded are counted. CANCELLED and EXPIRED never
 * had a position, so including them would drag the win rate toward zero and
 * describe a strategy nobody ran. That filter lives here, in one place, rather
 * than being re-typed into every caller's WHERE clause — a metric that is
 * "mostly" filtered consistently is worse than no metric.
 */
interface PerformanceRepositoryInterface
{
    /**
     * Headline totals for the window.
     *
     * @return array{
     *     total:int, wins:int, losses:int, breakeven:int,
     *     gross_profit_r:float, gross_loss_r:float, net_r:float,
     *     best_r:float|null, worst_r:float|null, avg_win_r:float|null, avg_loss_r:float|null,
     *     avg_score:float|null
     * }
     */
    public function summary(DateTimeImmutable $since, DateTimeImmutable $until, ?int $strategyId = null): array;

    /**
     * The closed sequence in order — the input to the equity curve and to any
     * path-dependent statistic (drawdown, streaks), which cannot be computed
     * from grouped totals.
     *
     * @return list<array{closed_at:string,realised_r:float,direction:string,state:string,uuid:string}>
     */
    public function closedSequence(DateTimeImmutable $since, DateTimeImmutable $until, ?int $strategyId = null): array;

    /**
     * Totals grouped by one of a fixed set of dimensions.
     *
     * The dimension is an enum-like whitelist, not a column name from the
     * request: this is the one place a reporting API tends to grow an
     * injection hole.
     *
     * @param 'direction'|'session'|'timeframe'|'strategy'|'hour'|'weekday'|'month' $dimension
     * @return list<array{bucket:string,total:int,wins:int,losses:int,net_r:float}>
     */
    public function breakdown(
        string $dimension,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        ?int $strategyId = null
    ): array;

    /**
     * Win rate and net R bucketed by score band — the chart that answers
     * "is the score actually predictive?", which is the only question that
     * justifies a threshold.
     *
     * @return list<array{band:string,low:int,total:int,wins:int,net_r:float}>
     */
    public function scoreBands(DateTimeImmutable $since, DateTimeImmutable $until, int $width = 5): array;

    /**
     * Per-target hit rates: how often TP1/TP2/TP3 were reached out of the
     * signals that activated.
     *
     * @return list<array{level:int,hit:int,eligible:int}>
     */
    public function targetHitRates(DateTimeImmutable $since, DateTimeImmutable $until): array;

    /**
     * Counts by state over the window, including the untraded outcomes that
     * the performance figures exclude — shown alongside so their exclusion is
     * visible rather than silent.
     *
     * @return array<string,int>
     */
    public function stateCounts(DateTimeImmutable $since, DateTimeImmutable $until): array;
}
