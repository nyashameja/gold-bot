<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use GoldBot\Core\Database;
use GoldBot\Domain\Signal\SignalState;
use GoldBot\Repositories\Contracts\PerformanceRepositoryInterface;
use GoldBot\Support\Uuid;
use InvalidArgumentException;

final class MySqlPerformanceRepository implements PerformanceRepositoryInterface
{
    /**
     * States that represent a position that actually traded. Built from the
     * enum rather than written out, so adding a terminal state cannot silently
     * leave the reporting behind.
     *
     * @var list<string>
     */
    private const TRADED = [
        SignalState::ClosedWin->value,
        SignalState::ClosedLoss->value,
        SignalState::ClosedBreakeven->value,
    ];

    /**
     * Whitelisted grouping expressions. Values are SQL fragments and are
     * NEVER built from request input — the key is what a caller may name.
     *
     * @var array<string,string>
     */
    private const DIMENSIONS = [
        'direction' => 's.direction',
        'session'   => "COALESCE(s.session_code, 'UNKNOWN')",
        'timeframe' => 'tf.code',
        'strategy'  => 'st.code',
        'hour'      => "LPAD(HOUR(s.generated_at), 2, '0')",
        'weekday'   => 'DAYNAME(s.generated_at)',
        'month'     => "DATE_FORMAT(s.closed_at, '%Y-%m')",
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function summary(DateTimeImmutable $since, DateTimeImmutable $until, ?int $strategyId = null): array
    {
        [$where, $bindings] = $this->tradedScope($since, $until, $strategyId);

        $row = $this->database->selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN s.state = 'CLOSED_WIN' THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN s.state = 'CLOSED_LOSS' THEN 1 ELSE 0 END) AS losses,
                SUM(CASE WHEN s.state = 'CLOSED_BREAKEVEN' THEN 1 ELSE 0 END) AS breakeven,
                COALESCE(SUM(CASE WHEN s.realised_r > 0 THEN s.realised_r ELSE 0 END), 0) AS gross_profit_r,
                COALESCE(SUM(CASE WHEN s.realised_r < 0 THEN -s.realised_r ELSE 0 END), 0) AS gross_loss_r,
                COALESCE(SUM(s.realised_r), 0) AS net_r,
                MAX(s.realised_r) AS best_r,
                MIN(s.realised_r) AS worst_r,
                AVG(CASE WHEN s.realised_r > 0 THEN s.realised_r END) AS avg_win_r,
                AVG(CASE WHEN s.realised_r < 0 THEN s.realised_r END) AS avg_loss_r,
                AVG(s.score) AS avg_score
             FROM signals s {$where}",
            $bindings
        ) ?? [];

        return [
            'total'          => (int) ($row['total'] ?? 0),
            'wins'           => (int) ($row['wins'] ?? 0),
            'losses'         => (int) ($row['losses'] ?? 0),
            'breakeven'      => (int) ($row['breakeven'] ?? 0),
            'gross_profit_r' => (float) ($row['gross_profit_r'] ?? 0),
            'gross_loss_r'   => (float) ($row['gross_loss_r'] ?? 0),
            'net_r'          => (float) ($row['net_r'] ?? 0),
            'best_r'         => isset($row['best_r']) ? (float) $row['best_r'] : null,
            'worst_r'        => isset($row['worst_r']) ? (float) $row['worst_r'] : null,
            'avg_win_r'      => isset($row['avg_win_r']) ? (float) $row['avg_win_r'] : null,
            'avg_loss_r'     => isset($row['avg_loss_r']) ? (float) $row['avg_loss_r'] : null,
            'avg_score'      => isset($row['avg_score']) ? (float) $row['avg_score'] : null,
        ];
    }

    public function closedSequence(DateTimeImmutable $since, DateTimeImmutable $until, ?int $strategyId = null): array
    {
        [$where, $bindings] = $this->tradedScope($since, $until, $strategyId);

        $rows = $this->database->select(
            "SELECT s.uuid, s.closed_at, s.realised_r, s.direction, s.state
             FROM signals s {$where}
             ORDER BY s.closed_at, s.id",
            $bindings
        );

        return array_map(
            static fn (array $r): array => [
                'uuid'       => Uuid::toString((string) $r['uuid']),
                'closed_at'  => (string) $r['closed_at'],
                'realised_r' => (float) $r['realised_r'],
                'direction'  => (string) $r['direction'],
                'state'      => (string) $r['state'],
            ],
            $rows
        );
    }

    public function breakdown(
        string $dimension,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        ?int $strategyId = null
    ): array {
        if (!isset(self::DIMENSIONS[$dimension])) {
            throw new InvalidArgumentException("Unknown performance dimension [{$dimension}].");
        }

        $expression = self::DIMENSIONS[$dimension];
        [$where, $bindings] = $this->tradedScope($since, $until, $strategyId);

        $rows = $this->database->select(
            "SELECT
                {$expression} AS bucket,
                COUNT(*) AS total,
                SUM(CASE WHEN s.state = 'CLOSED_WIN' THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN s.state = 'CLOSED_LOSS' THEN 1 ELSE 0 END) AS losses,
                COALESCE(SUM(s.realised_r), 0) AS net_r
             FROM signals s
             JOIN strategies st ON st.id = s.strategy_id
             JOIN timeframes tf ON tf.id = s.timeframe_id
             {$where}
             GROUP BY bucket
             ORDER BY bucket",
            $bindings
        );

        return array_map(
            static fn (array $r): array => [
                'bucket' => (string) $r['bucket'],
                'total'  => (int) $r['total'],
                'wins'   => (int) $r['wins'],
                'losses' => (int) $r['losses'],
                'net_r'  => (float) $r['net_r'],
            ],
            $rows
        );
    }

    public function scoreBands(DateTimeImmutable $since, DateTimeImmutable $until, int $width = 5): array
    {
        $width = max(1, min($width, 25));
        [$where, $bindings] = $this->tradedScope($since, $until, null);

        $rows = $this->database->select(
            "SELECT
                FLOOR(s.score / {$width}) * {$width} AS low,
                COUNT(*) AS total,
                SUM(CASE WHEN s.state = 'CLOSED_WIN' THEN 1 ELSE 0 END) AS wins,
                COALESCE(SUM(s.realised_r), 0) AS net_r
             FROM signals s {$where}
             GROUP BY low
             ORDER BY low",
            $bindings
        );

        return array_map(
            static function (array $r) use ($width): array {
                $low = (int) $r['low'];

                return [
                    'band'  => sprintf('%d–%d', $low, $low + $width - 1),
                    'low'   => $low,
                    'total' => (int) $r['total'],
                    'wins'  => (int) $r['wins'],
                    'net_r' => (float) $r['net_r'],
                ];
            },
            $rows
        );
    }

    public function targetHitRates(DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        // Eligibility is per level, not per signal: a signal with two targets
        // must not count against TP3's hit rate. Denominators that include
        // impossible cases understate the strategy.
        $rows = $this->database->select(
            "SELECT
                t.level,
                SUM(CASE WHEN t.hit_at IS NOT NULL THEN 1 ELSE 0 END) AS hit,
                COUNT(*) AS eligible
             FROM signal_targets t
             JOIN signals s ON s.id = t.signal_id
             WHERE s.activated_at IS NOT NULL
               AND s.closed_at >= ? AND s.closed_at < ?
               AND s.state IN ('CLOSED_WIN', 'CLOSED_LOSS', 'CLOSED_BREAKEVEN')
             GROUP BY t.level
             ORDER BY t.level",
            [$since->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s')]
        );

        return array_map(
            static fn (array $r): array => [
                'level'    => (int) $r['level'],
                'hit'      => (int) $r['hit'],
                'eligible' => (int) $r['eligible'],
            ],
            $rows
        );
    }

    public function stateCounts(DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        // Scoped by generation, not closure: a signal that expired never closed
        // and would otherwise be invisible here.
        $rows = $this->database->select(
            'SELECT state, COUNT(*) AS total FROM signals
             WHERE generated_at >= ? AND generated_at < ?
             GROUP BY state',
            [$since->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s')]
        );

        $counts = [];

        foreach (SignalState::cases() as $state) {
            $counts[$state->value] = 0;
        }

        foreach ($rows as $row) {
            $counts[(string) $row['state']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The traded-only scope shared by every aggregate above.
     *
     * @return array{0:string,1:list<mixed>}
     */
    private function tradedScope(DateTimeImmutable $since, DateTimeImmutable $until, ?int $strategyId): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::TRADED), '?'));

        $where = "WHERE s.state IN ({$placeholders}) AND s.closed_at >= ? AND s.closed_at < ?";
        $bindings = [...self::TRADED, $since->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s')];

        if ($strategyId !== null) {
            $where .= ' AND s.strategy_id = ?';
            $bindings[] = $strategyId;
        }

        return [$where, $bindings];
    }
}
