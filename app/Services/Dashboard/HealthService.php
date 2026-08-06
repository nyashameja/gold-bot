<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Core\Database;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\OperationsRepositoryInterface;
use GoldBot\Repositories\Contracts\PriceSnapshotRepositoryInterface;
use Throwable;

/**
 * System Health.
 *
 * The checks are computed live, on request, rather than only read back from
 * the health_checks table. The reason is the failure this page exists to
 * catch: if the scheduler has stopped, so has the health cron, and a page that
 * only replayed stored results would show the last cheerful green row it wrote
 * before everything died. A dashboard that cannot detect its own monitoring
 * having stopped is decorative.
 *
 * Stored history is shown alongside, for trend.
 *
 * Every check is a bounded query — counts and MAX() over indexed columns. This
 * page must stay cheap enough to open when things are already going wrong.
 */
final class HealthService
{
    public const OK       = 'OK';
    public const WARNING  = 'WARNING';
    public const CRITICAL = 'CRITICAL';
    public const UNKNOWN  = 'UNKNOWN';

    public function __construct(
        private readonly Database $database,
        private readonly OperationsRepositoryInterface $operations,
        private readonly MarketReferenceRepositoryInterface $reference,
        private readonly CandleRepositoryInterface $candles,
        private readonly PriceSnapshotRepositoryInterface $snapshots,
        private readonly ApiUsageService $apiUsage,
        private readonly TelegramBoardService $telegram,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function board(): array
    {
        $now = $this->clock->now();
        $checks = $this->runChecks($now);

        return [
            'overall'  => $this->worst(array_column($checks, 'status')),
            'checks'   => $checks,
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
        $checks = $this->runChecks($this->clock->now());

        return [
            'status'  => $this->worst(array_column($checks, 'status')),
            'failing' => array_values(array_map(
                static fn (array $c): string => (string) $c['label'],
                array_filter($checks, static fn (array $c): bool => $c['status'] !== self::OK)
            )),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function runChecks(DateTimeImmutable $now): array
    {
        return [
            $this->checkDatabase(),
            $this->checkScheduler($now),
            $this->checkMarketData($now),
            $this->checkPriceFeed($now),
            $this->checkCalendar($now),
            $this->checkApiBudget(),
            $this->checkTelegramQueue(),
            $this->checkStorage(),
        ];
    }

    /** @return array<string,mixed> */
    private function checkDatabase(): array
    {
        $started = microtime(true);

        try {
            $this->database->scalar('SELECT 1');
            $ms = (int) round((microtime(true) - $started) * 1000);

            return $this->check(
                'database',
                'Database',
                // A round trip over 500ms on the same host is not a slow query,
                // it is a sick server.
                $ms > 500 ? self::WARNING : self::OK,
                sprintf('Responding in %dms.', $ms),
                ['latency_ms' => $ms]
            );
        } catch (Throwable $e) {
            return $this->check('database', 'Database', self::CRITICAL, 'Unreachable: ' . $e->getMessage());
        }
    }

    /**
     * Overdue tasks — the failure that produces no error at all.
     *
     * A task that stops being invoked logs nothing, raises nothing, and simply
     * leaves the data it maintains getting quietly older. Measuring
     * last_success_at against the task's own cadence is the only way to see it.
     *
     * @return array<string,mixed>
     */
    private function checkScheduler(DateTimeImmutable $now): array
    {
        $overdue = [];
        $failing = [];
        $enabled = 0;

        foreach ($this->operations->scheduledTasks() as $task) {
            if ((int) $task['is_enabled'] !== 1) {
                continue;
            }

            $enabled++;
            $code = (string) $task['code'];
            $cadence = max(60, (int) $task['cadence_minutes'] * 60);

            if ((int) $task['consecutive_failures'] >= 3) {
                $failing[] = $code;
            }

            $lastSuccess = $task['last_success_at'] === null
                ? null
                : new DateTimeImmutable((string) $task['last_success_at'], new DateTimeZone('UTC'));

            // Three cadences of grace: shared hosting misses a minute now and
            // then, and alerting on the first miss trains people to ignore it.
            if ($lastSuccess === null || $now->getTimestamp() - $lastSuccess->getTimestamp() > $cadence * 3) {
                $overdue[] = $code;
            }
        }

        if ($enabled === 0) {
            return $this->check('scheduler', 'Scheduler', self::CRITICAL, 'No task is enabled — nothing will run.');
        }

        $status = match (true) {
            $overdue !== [] => self::CRITICAL,
            $failing !== [] => self::WARNING,
            default         => self::OK,
        };

        $message = match (true) {
            $overdue !== [] => sprintf('Overdue: %s.', implode(', ', $overdue)),
            $failing !== [] => sprintf('Repeated failures: %s.', implode(', ', $failing)),
            default         => sprintf('%d tasks running on schedule.', $enabled),
        };

        return $this->check('scheduler', 'Scheduler', $status, $message, [
            'enabled' => $enabled,
            'overdue' => $overdue,
            'failing' => $failing,
        ]);
    }

    /** @return array<string,mixed> */
    private function checkMarketData(DateTimeImmutable $now): array
    {
        $instruments = $this->reference->activeInstruments();

        if ($instruments === []) {
            return $this->check('market_data', 'Market data', self::CRITICAL, 'No active instrument configured.');
        }

        $instrumentId = (int) $instruments[0]['id'];
        $stale = [];
        $metrics = [];

        foreach ($this->reference->activeTimeframes() as $timeframe) {
            $candle = $this->candles->mostRecent($instrumentId, $timeframe->id, closedOnly: true);

            if ($candle === null) {
                $stale[] = $timeframe->code . ' (none)';
                $metrics[$timeframe->code] = null;
                continue;
            }

            $age = $now->getTimestamp() - $candle->closeTime->getTimestamp();
            $metrics[$timeframe->code] = $age;

            // Two bars of grace. The market also closes at the weekend, which
            // is why this is a warning and not a page: an H1 series is
            // legitimately 50 hours old on a Sunday.
            if ($age > $timeframe->seconds() * 2) {
                $stale[] = sprintf('%s (%dm)', $timeframe->code, intdiv($age, 60));
            }
        }

        return $this->check(
            'market_data',
            'Market data',
            $stale === [] ? self::OK : self::WARNING,
            $stale === []
                ? 'All timeframes current.'
                : 'Behind: ' . implode(', ', $stale) . '. Expected outside market hours.',
            ['age_seconds' => $metrics]
        );
    }

    /** @return array<string,mixed> */
    private function checkPriceFeed(DateTimeImmutable $now): array
    {
        $instruments = $this->reference->activeInstruments();

        if ($instruments === []) {
            return $this->check('price_feed', 'Price feed', self::UNKNOWN, 'No active instrument.');
        }

        $snapshot = $this->snapshots->latest((int) $instruments[0]['id']);

        if ($snapshot === null) {
            return $this->check('price_feed', 'Price feed', self::WARNING, 'No quote has been captured yet.');
        }

        $age = $snapshot->ageSeconds($now);

        $status = match (true) {
            $age > 1800 => self::CRITICAL,
            $age > 300  => self::WARNING,
            default     => self::OK,
        };

        return $this->check(
            'price_feed',
            'Price feed',
            $status,
            sprintf('Last quote %ds old.', $age),
            ['age_seconds' => $age, 'price' => $snapshot->price]
        );
    }

    /** @return array<string,mixed> */
    private function checkCalendar(DateTimeImmutable $now): array
    {
        $latest = $this->database->scalar(
            'SELECT MAX(last_seen_at) FROM economic_events WHERE retired_at IS NULL'
        );

        if ($latest === null) {
            return $this->check('calendar', 'Economic calendar', self::WARNING, 'No events imported.');
        }

        $age = $now->getTimestamp() - (new DateTimeImmutable((string) $latest, new DateTimeZone('UTC')))->getTimestamp();

        return $this->check(
            'calendar',
            'Economic calendar',
            // Imported hourly; six hours behind means the import is broken,
            // and the news filter is then silently passing everything.
            $age > 21600 ? self::WARNING : self::OK,
            sprintf('Last import %dh ago.', intdiv($age, 3600)),
            ['age_seconds' => $age]
        );
    }

    /** @return array<string,mixed> */
    private function checkApiBudget(): array
    {
        $providers = $this->apiUsage->summary();
        $worst = self::OK;
        $notes = [];

        foreach ($providers as $provider) {
            if ($provider['active'] !== true) {
                continue;
            }

            $worst = $this->worst([$worst, (string) $provider['status']]);

            if ($provider['percent_used'] !== null) {
                $notes[] = sprintf('%s %.0f%%', $provider['code'], $provider['percent_used']);
            }
        }

        return $this->check(
            'api_budget',
            'API budget',
            $worst,
            $notes === [] ? 'No quota consumed today.' : 'Daily quota: ' . implode(', ', $notes) . '.',
            ['providers' => $providers]
        );
    }

    /** @return array<string,mixed> */
    private function checkTelegramQueue(): array
    {
        $queue = $this->telegram->queueSummary();

        if ($queue['configured'] !== true) {
            return $this->check(
                'telegram',
                'Telegram delivery',
                self::WARNING,
                'No bot token configured — messages will queue but never send.',
                $queue
            );
        }

        return $this->check(
            'telegram',
            'Telegram delivery',
            (string) $queue['health'],
            sprintf('%d pending, %d failed, %d dead.', $queue['pending'], $queue['failed'], $queue['dead']),
            $queue
        );
    }

    /**
     * Writable storage and free space.
     *
     * A full disk on shared hosting presents as a cascade of unrelated-looking
     * errors — sessions failing, logs truncating, backups silently empty — so
     * it is worth naming directly.
     *
     * @return array<string,mixed>
     */
    private function checkStorage(): array
    {
        $paths = ['logs', 'cache', 'locks', 'backups'];
        $unwritable = [];

        foreach ($paths as $path) {
            $full = base_path('storage/' . $path);

            if (!is_dir($full) || !is_writable($full)) {
                $unwritable[] = $path;
            }
        }

        $free = @disk_free_space(base_path('storage'));
        $total = @disk_total_space(base_path('storage'));
        $percentFree = is_float($free) && is_float($total) && $total > 0
            ? round(($free / $total) * 100, 1)
            : null;

        $status = match (true) {
            $unwritable !== []                          => self::CRITICAL,
            $percentFree !== null && $percentFree < 5    => self::CRITICAL,
            $percentFree !== null && $percentFree < 15   => self::WARNING,
            default                                      => self::OK,
        };

        return $this->check(
            'storage',
            'Storage',
            $status,
            $unwritable !== []
                ? 'Not writable: ' . implode(', ', $unwritable) . '.'
                : ($percentFree === null ? 'Writable.' : sprintf('Writable, %.1f%% free.', $percentFree)),
            ['free_bytes' => is_float($free) ? (int) $free : null, 'percent_free' => $percentFree]
        );
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

    /**
     * @param array<string,mixed> $metrics
     * @return array<string,mixed>
     */
    private function check(string $component, string $label, string $status, string $message, array $metrics = []): array
    {
        return [
            'component' => $component,
            'label'     => $label,
            'status'    => $status,
            'message'   => $message,
            'metrics'   => $metrics,
        ];
    }

    /**
     * Worst status wins. An overall "OK" that averages away one critical
     * component is worse than no summary at all.
     *
     * @param list<string> $statuses
     */
    private function worst(array $statuses): string
    {
        $rank = [self::OK => 0, self::UNKNOWN => 1, self::WARNING => 2, self::CRITICAL => 3];
        $worst = self::OK;

        foreach ($statuses as $status) {
            if (($rank[$status] ?? 0) > $rank[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
