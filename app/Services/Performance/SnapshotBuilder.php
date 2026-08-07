<?php

declare(strict_types=1);

namespace GoldBot\Services\Performance;

use DateTimeImmutable;
use GoldBot\Domain\Performance\PerformanceCalculator;
use GoldBot\Domain\Performance\PeriodType;
use GoldBot\Domain\Performance\SnapshotScope;
use GoldBot\Repositories\Contracts\PerformanceSnapshotRepositoryInterface;
use Paragon\Core\Clock\ClockInterface;
use Paragon\Core\Logging\LoggerInterface;

/**
 * Builds the performance rollups (docs/02 §9).
 *
 * Every period is computed from scratch out of `signals` and written whole.
 * Nothing here adjusts a running total, and that is the central decision:
 *
 *   Signals change after they close. A late tick resolves one, an operator
 *   cancels another, a corrected candle moves an exit. An incrementally
 *   maintained aggregate drifts from the records it claims to summarise, and
 *   nothing in the system can detect the drift — the totals still look
 *   plausible. Recomputing a whole period is cheap (a bounded, indexed scan)
 *   and it converges: run it once or fifty times, the answer is the same.
 *
 * The snapshots are therefore a cache. Truncating the table costs a rebuild
 * and no information, which is the property that makes them safe to trust.
 */
final class SnapshotBuilder
{
    public function __construct(
        private readonly PerformanceSnapshotRepositoryInterface $snapshots,
        private readonly PerformanceCalculator $calculator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Rebuild every period that could contain $closedAt, across every scope.
     *
     * Called when a signal closes. Only the affected periods are touched — a
     * signal that closed on Tuesday cannot change last month's figures.
     *
     * @return int Snapshots written.
     */
    public function rebuildFor(DateTimeImmutable $closedAt): int
    {
        $written = 0;

        foreach (PeriodType::rollups() as $period) {
            $written += $this->buildPeriod($period, $period->startFor($closedAt));
        }

        $this->logger->info('Performance snapshots refreshed', [
            'event'     => 'performance.refreshed',
            'closed_at' => $closedAt->format(DATE_ATOM),
            'written'   => $written,
        ]);

        return $written;
    }

    /**
     * Rebuild the whole traded history.
     *
     * Runs nightly and on demand. Deletes first, so a scope that no longer has
     * any signals — a strategy that was removed, a session code that was
     * renamed — leaves no orphan row behind claiming a result.
     *
     * @return array{periods:int,snapshots:int,from:string|null,to:string|null}
     */
    public function rebuildAll(): array
    {
        $range = $this->snapshots->tradedRange();

        if ($range === null) {
            // No traded history at all. Clear anything stale and stop; writing
            // a row of zeros would assert a measurement nobody made.
            $this->snapshots->deleteAll();

            return ['periods' => 0, 'snapshots' => 0, 'from' => null, 'to' => null];
        }

        $this->snapshots->deleteAll();

        $periods = 0;
        $written = 0;

        foreach (PeriodType::rollups() as $period) {
            if ($period === PeriodType::AllTime) {
                $written += $this->buildPeriod($period, $period->startFor($range['first']));
                $periods++;
                continue;
            }

            $cursor = $period->startFor($range['first']);
            $end = $period->startFor($range['last']);

            // Inclusive of the last period, and bounded: a corrupt close time
            // far in the future would otherwise spin here forever.
            $guard = 0;

            while ($cursor <= $end && $guard < 10_000) {
                $written += $this->buildPeriod($period, $cursor);
                $periods++;
                $cursor = $period->endFor($cursor);
                $guard++;
            }
        }

        $this->logger->info('Performance snapshots rebuilt', [
            'event'     => 'performance.rebuilt',
            'periods'   => $periods,
            'snapshots' => $written,
            'from'      => $range['first']->format(DATE_ATOM),
            'to'        => $range['last']->format(DATE_ATOM),
        ]);

        return [
            'periods'   => $periods,
            'snapshots' => $written,
            'from'      => $range['first']->format(DATE_ATOM),
            'to'        => $range['last']->format(DATE_ATOM),
        ];
    }

    /**
     * Build one period across every scope that traded in it.
     *
     * @return int Snapshots written.
     */
    public function buildPeriod(PeriodType $period, DateTimeImmutable $start): int
    {
        $end = $period->endFor($start);
        $computedAt = $this->clock->now();
        $written = 0;

        foreach ($this->scopesFor() as $scope) {
            $outcomes = $this->snapshots->outcomes($start, $end, $scope);

            // A scope with nothing in this period gets no row. Writing zeros
            // for every strategy in every daily bucket would bloat the table
            // with rows that say only "this did not trade", which the absence
            // of a row already says.
            if ($outcomes === [] && !$scope->isOverall()) {
                continue;
            }

            $this->snapshots->store(
                $period,
                $start,
                $scope,
                $this->calculator->calculate($outcomes),
                $computedAt
            );

            $written++;
        }

        return $written;
    }

    /**
     * The scopes worth measuring: overall, plus one per dimension VALUE that
     * actually appears in the traded record.
     *
     * Deliberately not the cross product. Strategy × session × timeframe ×
     * direction is hundreds of combinations, almost all empty, and the
     * breakdowns the brief asks for are independent slices rather than
     * intersections.
     *
     * @return list<SnapshotScope>
     */
    private function scopesFor(): array
    {
        $known = $this->snapshots->knownScopes();

        $scopes = [SnapshotScope::overall()];

        foreach ($known['strategies'] as $strategyId) {
            $scopes[] = SnapshotScope::forStrategy($strategyId);
        }

        foreach ($known['sessions'] as $session) {
            $scopes[] = SnapshotScope::forSession($session);
        }

        foreach ($known['timeframes'] as $timeframeId) {
            $scopes[] = SnapshotScope::forTimeframe($timeframeId);
        }

        foreach ($known['directions'] as $direction) {
            $scopes[] = SnapshotScope::forDirection($direction);
        }

        return $scopes;
    }
}
