<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use GoldBot\Domain\Strategy\StrategyConfig;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
use Paragon\Core\Database;

final class MySqlStrategyRepository implements StrategyRepositoryInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function all(): array
    {
        // Includes disabled strategies. The engine must never run them, but the
        // 714 Method page must still be able to show one — a strategy that
        // becomes invisible the moment it is switched off cannot be reviewed
        // before being switched back on.
        return array_map(
            $this->castStrategy(...),
            $this->database->select(
                'SELECT id, code, name, description, class_name, is_enabled FROM strategies
                 WHERE deleted_at IS NULL
                 ORDER BY sort_order, id'
            )
        );
    }

    public function enabled(): array
    {
        return array_map(
            $this->castStrategy(...),
            $this->database->select(
                'SELECT id, code, name, description, class_name, is_enabled FROM strategies
                 WHERE is_enabled = 1 AND deleted_at IS NULL
                 ORDER BY sort_order, id'
            )
        );
    }

    public function findByCode(string $code): ?array
    {
        $row = $this->database->selectOne(
            'SELECT id, code, name, description, class_name, is_enabled FROM strategies
             WHERE code = ? AND deleted_at IS NULL',
            [$code]
        );

        return $row === null ? null : $this->castStrategy($row);
    }

    public function activeConfig(int $strategyId): ?StrategyConfig
    {
        $row = $this->database->selectOne(
            'SELECT id, strategy_id, version, config FROM strategy_configs
             WHERE strategy_id = ? AND is_active = 1
             ORDER BY version DESC LIMIT 1',
            [$strategyId]
        );

        return $row === null ? null : $this->toConfig($row);
    }

    public function configById(int $configId): ?StrategyConfig
    {
        $row = $this->database->selectOne(
            'SELECT id, strategy_id, version, config FROM strategy_configs WHERE id = ?',
            [$configId]
        );

        return $row === null ? null : $this->toConfig($row);
    }

    public function addConfigVersion(int $strategyId, array $config, ?string $notes = null, ?int $userId = null): int
    {
        return $this->database->transaction(function () use ($strategyId, $config, $notes, $userId): int {
            $version = (int) $this->database->scalar(
                'SELECT COALESCE(MAX(version), 0) + 1 FROM strategy_configs WHERE strategy_id = ?',
                [$strategyId]
            );

            // Deactivate the previous version rather than editing it. Old rows
            // are immutable so every past signal stays attributable (ADR-06).
            $this->database->run(
                'UPDATE strategy_configs SET is_active = 0 WHERE strategy_id = ?',
                [$strategyId]
            );

            return $this->database->insert('strategy_configs', [
                'strategy_id'  => $strategyId,
                'version'      => $version,
                'config'       => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                'notes'        => $notes,
                'is_active'    => 1,
                'created_by'   => $userId,
                'activated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ]);
        });
    }

    public function configHistory(int $strategyId): array
    {
        return $this->database->select(
            'SELECT c.id, c.version, c.notes, c.is_active, c.created_at, c.activated_at,
                    u.name AS created_by_name,
                    (SELECT COUNT(*) FROM signals s WHERE s.strategy_config_id = c.id) AS signal_count
             FROM strategy_configs c
             LEFT JOIN users u ON u.id = c.created_by
             WHERE c.strategy_id = ?
             ORDER BY c.version DESC',
            [$strategyId]
        );
    }

    public function recordRun(
        int $strategyId,
        int $configId,
        int $instrumentId,
        int $timeframeId,
        ?int $candleId,
        DateTimeImmutable $candleOpenTime,
        DateTimeImmutable $evaluatedAt,
        ?string $direction,
        float $score,
        bool $passed,
        ?string $rejectionReason,
        array $features,
        int $durationMs
    ): int {
        // INSERT IGNORE on the unique candle key: re-running the engine over a
        // window must not inflate the dataset with duplicate evaluations.
        $affected = $this->database->run(
            'INSERT IGNORE INTO strategy_runs
                (strategy_id, strategy_config_id, instrument_id, timeframe_id, candle_id,
                 evaluated_at, candle_open_time, direction, score, passed, rejection_reason,
                 features, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $strategyId,
                $configId,
                $instrumentId,
                $timeframeId,
                $candleId,
                $evaluatedAt->format('Y-m-d H:i:s.v'),
                $candleOpenTime->format('Y-m-d H:i:s'),
                $direction,
                $score,
                $passed ? 1 : 0,
                $rejectionReason === null ? null : mb_substr($rejectionReason, 0, 120),
                json_encode($features, JSON_UNESCAPED_SLASHES) ?: null,
                $durationMs,
            ]
        );

        return $affected === 0 ? 0 : (int) $this->database->pdo()->lastInsertId();
    }

    public function hasRunFor(int $strategyId, int $instrumentId, int $timeframeId, DateTimeImmutable $candleOpenTime): bool
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM strategy_runs
             WHERE strategy_id = ? AND instrument_id = ? AND timeframe_id = ? AND candle_open_time = ?',
            [$strategyId, $instrumentId, $timeframeId, $candleOpenTime->format('Y-m-d H:i:s')]
        ) > 0;
    }

    public function recentRuns(int $strategyId, int $limit = 50): array
    {
        return $this->database->select(
            'SELECT id, evaluated_at, candle_open_time, direction, score, passed, rejection_reason, duration_ms
             FROM strategy_runs
             WHERE strategy_id = ?
             ORDER BY candle_open_time DESC
             LIMIT ?',
            [$strategyId, max(1, min($limit, 500))]
        );
    }

    public function scoreDistribution(int $strategyId, DateTimeImmutable $since): array
    {
        $rows = $this->database->select(
            'SELECT FLOOR(score / 10) * 10 AS bucket, COUNT(*) AS total
             FROM strategy_runs
             WHERE strategy_id = ? AND evaluated_at >= ?
             GROUP BY bucket
             ORDER BY bucket',
            [$strategyId, $since->format('Y-m-d H:i:s')]
        );

        $distribution = [];

        foreach ($rows as $row) {
            $bucket = (int) $row['bucket'];
            $distribution[sprintf('%d-%d', $bucket, $bucket + 9)] = (int) $row['total'];
        }

        return $distribution;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,code:string,name:string,class_name:string}
     */
    private function castStrategy(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'code'        => (string) $row['code'],
            'name'        => (string) $row['name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'class_name'  => (string) $row['class_name'],
            'is_enabled'  => (int) ($row['is_enabled'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $row */
    private function toConfig(array $row): StrategyConfig
    {
        $decoded = json_decode((string) $row['config'], true);

        return new StrategyConfig(
            (int) $row['id'],
            (int) $row['strategy_id'],
            (int) $row['version'],
            is_array($decoded) ? $decoded : []
        );
    }
}
