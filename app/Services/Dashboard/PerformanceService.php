<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Performance\PerformanceCalculator;
use GoldBot\Domain\Performance\PeriodType;
use GoldBot\Domain\Performance\SnapshotScope;
use GoldBot\Domain\Performance\TradeOutcome;
use GoldBot\Repositories\Contracts\PerformanceRepositoryInterface;
use GoldBot\Repositories\Contracts\PerformanceSnapshotRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
use Paragon\Core\Clock\ClockInterface;

/**
 * What the Performance page reads.
 *
 * The metric arithmetic is NOT here. It lives in PerformanceCalculator, which
 * the nightly snapshot builder uses too — one implementation of every
 * definition, so the live page and the stored rollups cannot disagree. Two
 * implementations of "win rate" is how a dashboard ends up contradicting
 * itself, and a Performance page that disagrees with the Overview tile
 * discredits both numbers and every number beside them.
 *
 * This class assembles: it fetches, delegates the sums, and adds the
 * breakdowns and score bands that only the page needs.
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
        private readonly PerformanceCalculator $calculator,
        private readonly PerformanceSnapshotRepositoryInterface $snapshots,
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
            'summary'   => $this->metricsFor($sequence),
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
            'trend' => $this->trend($strategyId),
            'age'   => DataAge::since($this->lastClose($sequence), $now, 86400)->toArray(),
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

        return $this->metricsFor($this->performance->closedSequence($since, $now));
    }

    /**
     * The metrics for a fetched sequence.
     *
     * The repository's summary aggregates are deliberately NOT used for the
     * headline figures: they would be a second implementation of the same
     * definitions, computed in SQL, and the two would drift. The sequence is
     * already loaded for the equity curve, so measuring it costs nothing extra.
     *
     * @param list<array<string,mixed>> $sequence
     * @return array<string,mixed>
     */
    private function metricsFor(array $sequence): array
    {
        return $this->calculator->calculate($this->outcomes($sequence))->toArray();
    }

    /**
     * @param list<array<string,mixed>> $sequence
     * @return list<TradeOutcome>
     */
    private function outcomes(array $sequence): array
    {
        $utc = new DateTimeZone('UTC');

        return array_map(
            static fn (array $row): TradeOutcome => new TradeOutcome(
                new DateTimeImmutable((string) $row['closed_at'], $utc),
                (float) $row['realised_r']
            ),
            $sequence
        );
    }

    /**
     * Period-by-period metrics, read from the stored rollups.
     *
     * This is the one thing the snapshot table exists for. Every other figure
     * on this page is computed live from `signals` and would be no slower that
     * way — but "how did each of the last twenty weeks go?" means measuring
     * twenty separate windows, and drawdown and streaks do not add up across
     * period boundaries, so it cannot be assembled from a single scan. Reading
     * twenty precomputed rows can.
     *
     * Returns an empty series rather than falling back to a live computation
     * when nothing has been built: silently doing the expensive thing would
     * hide a scheduler that has stopped running the rebuild.
     *
     * @return array<string,mixed>
     */
    private function trend(?int $strategyId): array
    {
        $scope = $strategyId === null
            ? SnapshotScope::overall()
            : SnapshotScope::forStrategy($strategyId);

        $periods = [];

        foreach ([PeriodType::Daily, PeriodType::Weekly, PeriodType::Monthly] as $period) {
            $periods[strtolower($period->value)] = array_map(
                static fn (array $row): array => [
                    'start'   => $row['start'],
                    'label'   => $period->format(new DateTimeImmutable($row['start'], new DateTimeZone('UTC'))),
                    'metrics' => $row['metrics']->toArray(),
                ],
                $this->snapshots->series($period, $scope, 30)
            );
        }

        $allTime = $this->snapshots->find(
            PeriodType::AllTime,
            PeriodType::AllTime->startFor($this->clock->now()),
            $scope
        );

        return [
            'periods'   => $periods,
            'all_time'  => $allTime === null ? null : $allTime['metrics']->toArray(),
            'built_at'  => $allTime['computed_at'] ?? null,
            'available' => $this->snapshots->count() > 0,
        ];
    }

    /**
     * The cumulative R curve.
     *
     * The running total comes from the calculator, so the last point of this
     * curve and the net R in the tiles above it are the same arithmetic. The
     * signal uuid is stitched back on afterwards, because the chart links each
     * point to the signal that produced it and the domain has no business
     * knowing about that. The indices line up because the repository already
     * returns the sequence ordered by close time and PHP's sort is stable, so
     * the calculator's ordering is a no-op on this input.
     *
     * @param list<array<string,mixed>> $sequence
     * @return list<array{t:string,equity:float,r:float,uuid:string}>
     */
    private function equityCurve(array $sequence): array
    {
        $points = $this->calculator->equityCurve($this->outcomes($sequence));
        $curve = [];

        foreach ($points as $index => $point) {
            $curve[] = [
                't'      => $point['at'],
                'equity' => $point['equity'],
                'r'      => $point['r'],
                'uuid'   => (string) ($sequence[$index]['uuid'] ?? ''),
            ];
        }

        return $curve;
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
