<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Repositories\Contracts\OperationsRepositoryInterface;
use GoldBot\Services\Health\HealthChecker;

/**
 * What the System Health page reads.
 *
 * The checks themselves live in HealthChecker, which the cron runs too — one
 * implementation, so the page and the alert cannot report different things
 * about the same component.
 *
 * They are run LIVE here rather than read back from health_checks, which is
 * the point of the page: if the scheduler has stopped then so has the health
 * cron, and a page that only replayed stored results would show the last
 * cheerful green row it managed to write before everything died. A dashboard
 * that cannot detect its own monitoring having stopped is decorative. The
 * stored history is shown alongside, for trend.
 */
final class HealthService
{
    public function __construct(
        private readonly HealthChecker $checker,
        private readonly OperationsRepositoryInterface $operations,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function board(): array
    {
        $now = $this->clock->now();
        $reports = $this->checker->run();

        return [
            'overall'  => $this->checker->overall($reports)->value,
            'checks'   => array_map(static fn ($report): array => $report->toArray(), $reports),
            'tasks'    => $this->tasks($now),
            'reliability' => $this->operations->taskReliability($now->modify('-7 days')),
            'stored'   => $this->operations->latestHealthChecks(),
            'logs'     => $this->operations->recentLogs(25),
            'log_counts' => $this->operations->logLevelCounts($now->modify('-24 hours')),
            'tables'   => $this->tables(),
            'runtime'  => $this->runtime(),
            'checked_at' => $now->format(DATE_ATOM),
        ];
    }

    /**
     * The Overview's single health pill.
     *
     * @return array{status:string,failing:list<string>}
     */
    public function summary(): array
    {
        $reports = $this->checker->run();

        return [
            'status'  => $this->checker->overall($reports)->value,
            'failing' => array_values(array_map(
                static fn ($report): string => $report->label,
                array_filter($reports, static fn ($report): bool => $report->status->isDegraded())
            )),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function tasks(DateTimeImmutable $now): array
    {
        return array_map(
            function (array $task) use ($now): array {
                $cadence = max(60, (int) $task['cadence_minutes'] * 60);

                $lastSuccess = $task['last_success_at'] === null
                    ? null
                    : new DateTimeImmutable((string) $task['last_success_at'], new DateTimeZone('UTC'));

                return [
                    'code'      => (string) $task['code'],
                    'name'      => (string) $task['name'],
                    'enabled'   => (int) $task['is_enabled'] === 1,
                    'cadence_minutes' => (int) $task['cadence_minutes'],
                    'next_due_at'     => $task['next_due_at'] === null ? null : (string) $task['next_due_at'],
                    'last_status'     => $task['last_status'] === null ? null : (string) $task['last_status'],
                    'last_output'     => $task['last_output'] === null ? null : (string) $task['last_output'],
                    'last_error'      => $task['last_error'] === null ? null : (string) $task['last_error'],
                    'last_duration_ms' => $task['last_duration_ms'] === null ? null : (int) $task['last_duration_ms'],
                    'consecutive_failures' => (int) $task['consecutive_failures'],
                    'age'       => DataAge::since($lastSuccess, $now, $cadence)->toArray(),
                ];
            },
            $this->operations->scheduledTasks()
        );
    }

    /** @return array<string,mixed> */
    private function tables(): array
    {
        $tables = $this->operations->tableSizes();

        return [
            'rows'  => $tables,
            'total_bytes' => array_sum(array_column($tables, 'size_bytes')),
        ];
    }

    /**
     * Environment facts worth having on screen when diagnosing a report.
     *
     * No credentials, no connection strings — this page is visible to anyone
     * with health.view, which is not the same as anyone who may see secrets.
     *
     * @return array<string,mixed>
     */
    private function runtime(): array
    {
        return [
            'php_version'   => PHP_VERSION,
            'server_time'   => $this->clock->now()->format(DATE_ATOM),
            'timezone'      => date_default_timezone_get(),
            'memory_limit'  => (string) ini_get('memory_limit'),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1_048_576, 1),
            'extensions'    => [
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'curl'      => extension_loaded('curl'),
                'mbstring'  => extension_loaded('mbstring'),
                'apcu'      => extension_loaded('apcu'),
                'sodium'    => extension_loaded('sodium'),
            ],
        ];
    }
}
