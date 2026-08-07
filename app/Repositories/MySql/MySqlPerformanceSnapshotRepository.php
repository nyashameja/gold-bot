<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Core\Database;
use GoldBot\Domain\Performance\MetricSet;
use GoldBot\Domain\Performance\PeriodType;
use GoldBot\Domain\Performance\SnapshotScope;
use GoldBot\Domain\Performance\TradeOutcome;
use GoldBot\Domain\Signal\SignalState;
use GoldBot\Repositories\Contracts\PerformanceSnapshotRepositoryInterface;

final class MySqlPerformanceSnapshotRepository implements PerformanceSnapshotRepositoryInterface
{
    /**
     * States that represent a position that actually traded. Built from the
     * enum rather than written out, so adding a terminal state cannot leave
     * the reporting quietly behind.
     *
     * @var list<string>
     */
    private const TRADED = [
        SignalState::ClosedWin->value,
        SignalState::ClosedLoss->value,
        SignalState::ClosedBreakeven->value,
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function outcomes(DateTimeImmutable $from, DateTimeImmutable $until, SnapshotScope $scope): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::TRADED), '?'));

        $sql = "SELECT s.closed_at, s.realised_r, s.risk_reward, s.score
                FROM signals s
                WHERE s.state IN ({$placeholders})
                  AND s.realised_r IS NOT NULL
                  AND s.closed_at >= ? AND s.closed_at < ?";

        $bindings = [
            ...self::TRADED,
            $from->format('Y-m-d H:i:s'),
            $until->format('Y-m-d H:i:s'),
        ];

        // Dimension columns are literals in this file; only values are bound.
        $filters = [
            's.strategy_id'   => $scope->strategyId,
            's.instrument_id' => $scope->instrumentId,
            's.session_code'  => $scope->sessionCode,
            's.timeframe_id'  => $scope->timeframeId,
            's.direction'     => $scope->direction,
        ];

        foreach ($filters as $column => $value) {
            if ($value === null) {
                continue;
            }

            $sql .= " AND {$column} = ?";
            $bindings[] = $value;
        }

        return array_map(
            static fn (array $row): TradeOutcome => TradeOutcome::fromRow($row),
            $this->database->select($sql . ' ORDER BY s.closed_at, s.id', $bindings)
        );
    }

    public function store(
        PeriodType $period,
        DateTimeImmutable $start,
        SnapshotScope $scope,
        MetricSet $metrics,
        DateTimeImmutable $computedAt
    ): void {
        $values = [
            'period_type'  => $period->value,
            'period_start' => $start->format('Y-m-d H:i:s'),
            'period_end'   => $period->endFor($start)->format('Y-m-d H:i:s'),
            ...$scope->toColumns(),
            ...$metrics->toColumns(),
            'computed_at'  => $computedAt->format('Y-m-d H:i:s.v'),
        ];

        // Upsert on (period_type, period_start, scope_key): a rebuild replaces
        // the row rather than accumulating duplicates, so running it twice is
        // indistinguishable from running it once.
        $this->database->upsert(
            'performance_snapshots',
            $values,
            [...array_keys($metrics->toColumns()), 'period_end', 'computed_at']
        );
    }

    public function find(PeriodType $period, DateTimeImmutable $start, SnapshotScope $scope): ?array
    {
        $row = $this->database->selectOne(
            'SELECT * FROM performance_snapshots
             WHERE period_type = ? AND period_start = ? AND scope_key = ?',
            [$period->value, $start->format('Y-m-d H:i:s'), $scope->key()]
        );

        if ($row === null) {
            return null;
        }

        return [
            'scope'       => SnapshotScope::fromColumns($row),
            'metrics'     => MetricSet::fromColumns($row),
            'computed_at' => (string) $row['computed_at'],
        ];
    }

    public function series(PeriodType $period, SnapshotScope $scope, int $limit = 90): array
    {
        // Newest N, then reversed: taking the oldest N would show a chart the
        // beginning of history rather than the part anyone is looking at.
        $rows = $this->database->select(
            'SELECT * FROM performance_snapshots
             WHERE period_type = ? AND scope_key = ?
             ORDER BY period_start DESC
             LIMIT ?',
            [$period->value, $scope->key(), max(1, min($limit, 500))]
        );

        return array_map(
            static fn (array $row): array => [
                'start'   => (string) $row['period_start'],
                'end'     => (string) $row['period_end'],
                'metrics' => MetricSet::fromColumns($row),
            ],
            array_reverse($rows)
        );
    }

    public function forPeriod(PeriodType $period, DateTimeImmutable $start): array
    {
        $rows = $this->database->select(
            'SELECT * FROM performance_snapshots
             WHERE period_type = ? AND period_start = ?
             ORDER BY total_signals DESC, scope_key',
            [$period->value, $start->format('Y-m-d H:i:s')]
        );

        return array_map(
            static fn (array $row): array => [
                'scope'   => SnapshotScope::fromColumns($row),
                'metrics' => MetricSet::fromColumns($row),
            ],
            $rows
        );
    }

    public function tradedRange(): ?array
    {
        $placeholders = implode(', ', array_fill(0, count(self::TRADED), '?'));

        $row = $this->database->selectOne(
            "SELECT MIN(closed_at) AS first_close, MAX(closed_at) AS last_close
             FROM signals
             WHERE state IN ({$placeholders}) AND closed_at IS NOT NULL",
            self::TRADED
        );

        if ($row === null || $row['first_close'] === null) {
            return null;
        }

        $utc = new DateTimeZone('UTC');

        return [
            'first' => new DateTimeImmutable((string) $row['first_close'], $utc),
            'last'  => new DateTimeImmutable((string) $row['last_close'], $utc),
        ];
    }

    public function knownScopes(): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::TRADED), '?'));

        // One query per dimension rather than a cross product: the breakdowns
        // are independent slices, and a combined DISTINCT would enumerate
        // combinations that never traded.
        $strategies = $this->database->select(
            "SELECT DISTINCT strategy_id FROM signals WHERE state IN ({$placeholders})",
            self::TRADED
        );

        $sessions = $this->database->select(
            "SELECT DISTINCT session_code FROM signals
             WHERE state IN ({$placeholders}) AND session_code IS NOT NULL",
            self::TRADED
        );

        $timeframes = $this->database->select(
            "SELECT DISTINCT timeframe_id FROM signals WHERE state IN ({$placeholders})",
            self::TRADED
        );

        $directions = $this->database->select(
            "SELECT DISTINCT direction FROM signals WHERE state IN ({$placeholders})",
            self::TRADED
        );

        return [
            'strategies' => array_map(static fn (array $r): int => (int) $r['strategy_id'], $strategies),
            'sessions'   => array_map(static fn (array $r): string => (string) $r['session_code'], $sessions),
            'timeframes' => array_map(static fn (array $r): int => (int) $r['timeframe_id'], $timeframes),
            'directions' => array_map(static fn (array $r): string => (string) $r['direction'], $directions),
        ];
    }

    public function deleteAll(): int
    {
        return $this->database->run('DELETE FROM performance_snapshots');
    }

    public function count(): int
    {
        return (int) $this->database->scalar('SELECT COUNT(*) FROM performance_snapshots');
    }
}
