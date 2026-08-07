<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use GoldBot\Core\Database;
use GoldBot\Repositories\Contracts\OperationsRepositoryInterface;

final class MySqlOperationsRepository implements OperationsRepositoryInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function providerUsage(DateTimeImmutable $now): array
    {
        // Correlated subqueries rather than three joins: each window is a
        // different range over the same index (provider_id, requested_at), and
        // joining them would multiply rows before aggregating them.
        $dayStart = $now->setTime(0, 0)->format('Y-m-d H:i:s');
        $minuteAgo = $now->modify('-60 seconds')->format('Y-m-d H:i:s.v');
        $hourAgo = $now->modify('-1 hour')->format('Y-m-d H:i:s.v');

        return $this->database->select(
            'SELECT
                p.id,
                p.code,
                p.name,
                p.daily_limit,
                p.per_minute_limit,
                p.is_active,
                (SELECT COALESCE(SUM(u.credits_used), 0) FROM api_usage_log u
                  WHERE u.provider_id = p.id AND u.requested_at >= ?) AS credits_today,
                (SELECT COUNT(*) FROM api_usage_log u
                  WHERE u.provider_id = p.id AND u.requested_at >= ?) AS calls_today,
                (SELECT COUNT(*) FROM api_usage_log u
                  WHERE u.provider_id = p.id AND u.requested_at >= ?) AS calls_last_minute,
                (SELECT COUNT(*) FROM api_usage_log u
                  WHERE u.provider_id = p.id AND u.succeeded = 0 AND u.requested_at >= ?) AS failures_last_hour,
                (SELECT ROUND(AVG(u.response_time_ms)) FROM api_usage_log u
                  WHERE u.provider_id = p.id AND u.succeeded = 1 AND u.requested_at >= ?) AS avg_ms_last_hour,
                (SELECT MAX(u.requested_at) FROM api_usage_log u
                  WHERE u.provider_id = p.id) AS last_call_at
             FROM api_providers p
             ORDER BY p.is_active DESC, p.code',
            [$dayStart, $dayStart, $minuteAgo, $hourAgo, $hourAgo]
        );
    }

    public function usageByHour(DateTimeImmutable $since, DateTimeImmutable $until, ?int $providerId = null): array
    {
        $sql = "SELECT
                    DATE_FORMAT(requested_at, '%Y-%m-%d %H:00:00') AS bucket,
                    COUNT(*) AS calls,
                    COALESCE(SUM(credits_used), 0) AS credits,
                    SUM(CASE WHEN succeeded = 0 THEN 1 ELSE 0 END) AS failures,
                    ROUND(AVG(CASE WHEN succeeded = 1 THEN response_time_ms END), 1) AS avg_ms
                FROM api_usage_log
                WHERE requested_at >= ? AND requested_at < ?";

        $bindings = [$since->format('Y-m-d H:i:s.v'), $until->format('Y-m-d H:i:s.v')];

        if ($providerId !== null) {
            $sql .= ' AND provider_id = ?';
            $bindings[] = $providerId;
        }

        $rows = $this->database->select($sql . ' GROUP BY bucket ORDER BY bucket', $bindings);

        return array_map(
            static fn (array $r): array => [
                'bucket'   => (string) $r['bucket'],
                'calls'    => (int) $r['calls'],
                'credits'  => (int) $r['credits'],
                'failures' => (int) $r['failures'],
                'avg_ms'   => $r['avg_ms'] === null ? null : (float) $r['avg_ms'],
            ],
            $rows
        );
    }

    public function usageByEndpoint(DateTimeImmutable $since, int $limit = 20): array
    {
        return $this->database->select(
            'SELECT
                p.code AS provider_code,
                u.endpoint,
                COUNT(*) AS calls,
                COALESCE(SUM(u.credits_used), 0) AS credits,
                SUM(CASE WHEN u.succeeded = 0 THEN 1 ELSE 0 END) AS failures,
                ROUND(AVG(CASE WHEN u.succeeded = 1 THEN u.response_time_ms END), 1) AS avg_ms,
                MAX(u.requested_at) AS last_call_at
             FROM api_usage_log u
             JOIN api_providers p ON p.id = u.provider_id
             WHERE u.requested_at >= ?
             GROUP BY p.code, u.endpoint
             ORDER BY credits DESC, calls DESC
             LIMIT ?',
            [$since->format('Y-m-d H:i:s.v'), max(1, min($limit, 100))]
        );
    }

    public function recentApiFailures(int $limit = 20): array
    {
        return $this->database->select(
            'SELECT p.code AS provider_code, u.endpoint, u.http_status, u.error_message,
                    u.response_time_ms, u.requested_at
             FROM api_usage_log u
             JOIN api_providers p ON p.id = u.provider_id
             WHERE u.succeeded = 0
             ORDER BY u.requested_at DESC
             LIMIT ?',
            [max(1, min($limit, 100))]
        );
    }

    public function scheduledTasks(): array
    {
        // The lateral-style join is done with a correlated MAX(id) rather than
        // a window function so the query works on MySQL 5.7 hosts too; cPanel
        // fleets are not uniformly on 8.
        return $this->database->select(
            'SELECT
                t.*,
                r.status        AS last_status,
                r.started_at    AS last_started_at,
                r.finished_at   AS last_finished_at,
                r.duration_ms   AS last_duration_ms,
                r.items_processed AS last_items,
                r.output        AS last_output,
                r.error_message AS last_error
             FROM scheduled_tasks t
             LEFT JOIN task_runs r
                    ON r.id = (SELECT MAX(r2.id) FROM task_runs r2 WHERE r2.task_id = t.id)
             ORDER BY t.sort_order, t.code'
        );
    }

    public function recentTaskRuns(int $limit = 50, ?string $taskCode = null): array
    {
        $sql = 'SELECT r.*, t.code AS task_code, t.name AS task_name
                FROM task_runs r
                JOIN scheduled_tasks t ON t.id = r.task_id';
        $bindings = [];

        if ($taskCode !== null) {
            $sql .= ' WHERE t.code = ?';
            $bindings[] = $taskCode;
        }

        $bindings[] = max(1, min($limit, 200));

        return $this->database->select($sql . ' ORDER BY r.started_at DESC, r.id DESC LIMIT ?', $bindings);
    }

    public function taskReliability(DateTimeImmutable $since): array
    {
        return $this->database->select(
            "SELECT
                t.code,
                t.name,
                COUNT(r.id) AS runs,
                SUM(CASE WHEN r.status = 'SUCCESS' THEN 1 ELSE 0 END) AS successes,
                SUM(CASE WHEN r.status = 'FAILED' THEN 1 ELSE 0 END) AS failures,
                SUM(CASE WHEN r.status LIKE 'SKIPPED%' THEN 1 ELSE 0 END) AS skips,
                ROUND(AVG(r.duration_ms)) AS avg_duration_ms,
                MAX(r.duration_ms) AS max_duration_ms
             FROM scheduled_tasks t
             LEFT JOIN task_runs r ON r.task_id = t.id AND r.started_at >= ?
             GROUP BY t.id, t.code, t.name
             ORDER BY t.sort_order, t.code",
            [$since->format('Y-m-d H:i:s.v')]
        );
    }

    public function latestHealthChecks(): array
    {
        return $this->database->select(
            'SELECT h.*
             FROM health_checks h
             JOIN (SELECT component, MAX(id) AS id FROM health_checks GROUP BY component) latest
               ON latest.id = h.id
             ORDER BY h.component'
        );
    }

    public function recentLogs(int $limit = 50, ?string $level = null, ?string $channel = null): array
    {
        $sql = 'SELECT id, level, channel, event, message, context, created_at
                FROM system_logs';
        $where = [];
        $bindings = [];

        if ($level !== null) {
            $where[] = 'level = ?';
            $bindings[] = $level;
        }

        if ($channel !== null) {
            $where[] = 'channel = ?';
            $bindings[] = $channel;
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $bindings[] = max(1, min($limit, 200));

        return $this->database->select($sql . ' ORDER BY created_at DESC, id DESC LIMIT ?', $bindings);
    }

    public function logLevelCounts(DateTimeImmutable $since): array
    {
        $rows = $this->database->select(
            'SELECT level, COUNT(*) AS total FROM system_logs
             WHERE created_at >= ? GROUP BY level ORDER BY total DESC',
            [$since->format('Y-m-d H:i:s.v')]
        );

        return array_map(
            static fn (array $r): array => ['level' => (string) $r['level'], 'total' => (int) $r['total']],
            $rows
        );
    }

    public function tableSizes(): array
    {
        $rows = $this->database->select(
            'SELECT table_name, table_rows, (data_length + index_length) AS size_bytes
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = ?
             ORDER BY size_bytes DESC',
            ['BASE TABLE']
        );

        return array_map(
            static fn (array $r): array => [
                // information_schema column case varies by MySQL version.
                'table_name'   => (string) ($r['table_name'] ?? $r['TABLE_NAME'] ?? ''),
                'row_estimate' => (int) ($r['table_rows'] ?? $r['TABLE_ROWS'] ?? 0),
                'size_bytes'   => (int) ($r['size_bytes'] ?? 0),
            ],
            $rows
        );
    }

    public function recordHealthCheck(
        string $component,
        string $status,
        ?string $message,
        ?array $metrics,
        ?int $durationMs,
        DateTimeImmutable $checkedAt
    ): void {
        $this->database->insert('health_checks', [
            'component'   => $component,
            'status'      => $status,
            // Truncated to the column width rather than trusted to fit. A
            // message that overflows takes the whole health check down with a
            // PDOException — losing the monitoring at exactly the moment it
            // had something to say. Callers should keep messages short; this
            // makes it impossible for one that does not to be fatal.
            'message'     => $message === null ? null : mb_substr($message, 0, 255),
            'metrics'     => $metrics === null ? null : (json_encode($metrics, JSON_UNESCAPED_SLASHES) ?: null),
            'duration_ms' => $durationMs,
            'checked_at'  => $checkedAt->format('Y-m-d H:i:s.v'),
        ]);
    }
}
