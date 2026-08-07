<?php

declare(strict_types=1);

namespace GoldBot\Services\Health;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Health\HealthReport;
use GoldBot\Domain\Health\HealthStatus;
use GoldBot\Integrations\Telegram\TelegramClientInterface;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\OperationsRepositoryInterface;
use GoldBot\Repositories\Contracts\PriceSnapshotRepositoryInterface;
use GoldBot\Repositories\Contracts\TelegramRepositoryInterface;
use Paragon\Core\Clock\ClockInterface;
use Paragon\Core\Database;
use Throwable;

/**
 * Every component check in docs/01 §11.
 *
 * ONE implementation, run by both the System Health page and the cron. The
 * page needs them computed live — if the scheduler has stopped then so has the
 * health cron, and a page that only replayed stored results would show the
 * last cheerful green row it managed to write before everything died. The cron
 * needs them persisted, so a problem can be seen to be new or chronic. Same
 * checks either way; only what happens to the results differs.
 *
 * Every check is bounded: counts and MAX() over indexed columns, no table
 * scans, no network. This has to stay cheap enough to run when things are
 * already going wrong.
 */
final class HealthChecker
{
    /** Components in the order an operator wants to read them. */
    public const COMPONENTS = [
        'database', 'scheduler', 'market_data', 'price_feed', 'calendar',
        'api_providers', 'telegram', 'error_rate', 'storage', 'logs',
    ];

    public function __construct(
        private readonly Database $database,
        private readonly OperationsRepositoryInterface $operations,
        private readonly MarketReferenceRepositoryInterface $reference,
        private readonly CandleRepositoryInterface $candles,
        private readonly PriceSnapshotRepositoryInterface $snapshots,
        private readonly TelegramRepositoryInterface $telegram,
        private readonly TelegramClientInterface $telegramClient,
        private readonly ClockInterface $clock,
        private readonly string $storagePath,
        private readonly string $logPath
    ) {
    }

    /**
     * Run everything.
     *
     * A check that throws becomes a CRITICAL report rather than an exception:
     * one broken check must not take down the page that would have shown the
     * other nine.
     *
     * @return list<HealthReport>
     */
    public function run(): array
    {
        $checks = [
            'database'      => fn (): HealthReport => $this->checkDatabase(),
            'scheduler'     => fn (): HealthReport => $this->checkScheduler(),
            'market_data'   => fn (): HealthReport => $this->checkMarketData(),
            'price_feed'    => fn (): HealthReport => $this->checkPriceFeed(),
            'calendar'      => fn (): HealthReport => $this->checkCalendar(),
            'api_providers' => fn (): HealthReport => $this->checkApiProviders(),
            'telegram'      => fn (): HealthReport => $this->checkTelegram(),
            'error_rate'    => fn (): HealthReport => $this->checkErrorRate(),
            'storage'       => fn (): HealthReport => $this->checkStorage(),
            'logs'          => fn (): HealthReport => $this->checkLogs(),
        ];

        $reports = [];

        foreach ($checks as $component => $check) {
            $started = microtime(true);

            try {
                $report = $check();
            } catch (Throwable $e) {
                $report = HealthReport::critical(
                    $component,
                    ucwords(str_replace('_', ' ', $component)),
                    'The check itself failed: ' . $e->getMessage()
                );
            }

            $reports[] = $report->withDuration((int) round((microtime(true) - $started) * 1000));
        }

        return $reports;
    }

    /** @param list<HealthReport> $reports */
    public function overall(array $reports): HealthStatus
    {
        return HealthStatus::worst(array_map(
            static fn (HealthReport $r): HealthStatus => $r->status,
            $reports
        ));
    }

    // ── Checks ───────────────────────────────────────────────────────────────

    private function checkDatabase(): HealthReport
    {
        $started = microtime(true);
        $this->database->scalar('SELECT 1');
        $ms = (int) round((microtime(true) - $started) * 1000);

        // Over half a second for a round trip on the same host is not a slow
        // query, it is a sick server.
        return $ms > 500
            ? HealthReport::warning('database', 'Database', sprintf('Responding slowly: %dms.', $ms), ['latency_ms' => $ms])
            : HealthReport::ok('database', 'Database', sprintf('Responding in %dms.', $ms), ['latency_ms' => $ms]);
    }

    /**
     * The check that catches the failure mode that actually hurts.
     *
     * A cron that stopped running produces no errors at all — no exception, no
     * log line, nothing. It simply leaves every piece of data it maintains
     * getting quietly older. Comparing last-success against the task's own
     * expected cadence is the only way to notice (docs/01 §11).
     */
    private function checkScheduler(): HealthReport
    {
        $now = $this->clock->now();
        $overdue = [];
        $failing = [];
        $disabled = [];
        $enabled = 0;

        foreach ($this->operations->scheduledTasks() as $task) {
            if ((int) $task['is_enabled'] !== 1) {
                // Reported, not ignored. Disabling a task is a deliberate act,
                // so it is not CRITICAL — but a "temporary" disable that was
                // forgotten is exactly how a platform stops working with
                // nothing complaining, which is the failure this whole check
                // exists to catch. Visible without being noisy.
                $disabled[] = (string) $task['code'];
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

            // Three cadences of grace. Shared hosting misses a minute now and
            // then, and alerting on the first miss trains people to ignore the
            // alert — which costs more than the miss did.
            $age = $lastSuccess === null ? null : $now->getTimestamp() - $lastSuccess->getTimestamp();

            if ($age === null || $age > $cadence * 3) {
                $overdue[] = sprintf('%s (%s)', $code, $age === null ? 'never' : $this->duration($age));
            }
        }

        if ($enabled === 0) {
            return HealthReport::critical('scheduler', 'Scheduler', 'No task is enabled — nothing will run.');
        }

        $metrics = [
            'enabled'  => $enabled,
            'overdue'  => $overdue,
            'failing'  => $failing,
            'disabled' => $disabled,
        ];

        $note = $disabled === []
            ? ''
            : sprintf(' %d task(s) disabled: %s.', count($disabled), $this->summarise($disabled));

        if ($overdue !== []) {
            return HealthReport::critical(
                'scheduler',
                'Scheduler',
                'Overdue: ' . $this->summarise($overdue) . '.' . $note,
                $metrics
            );
        }

        if ($failing !== []) {
            return HealthReport::warning(
                'scheduler',
                'Scheduler',
                'Repeated failures: ' . $this->summarise($failing) . '.' . $note,
                $metrics
            );
        }

        if ($disabled !== []) {
            return HealthReport::warning(
                'scheduler',
                'Scheduler',
                sprintf('%d task(s) running on schedule.', $enabled) . $note
                . ' A disabled task collects no data and raises no error of its own.',
                $metrics
            );
        }

        return HealthReport::ok('scheduler', 'Scheduler', sprintf('%d tasks running on schedule.', $enabled), $metrics);
    }

    private function checkMarketData(): HealthReport
    {
        $now = $this->clock->now();
        $instruments = $this->reference->activeInstruments();

        if ($instruments === []) {
            return HealthReport::critical('market_data', 'Market data', 'No active instrument configured.');
        }

        $instrumentId = (int) $instruments[0]['id'];
        $stale = [];
        $ages = [];

        foreach ($this->reference->activeTimeframes() as $timeframe) {
            $candle = $this->candles->mostRecent($instrumentId, $timeframe->id, closedOnly: true);

            if ($candle === null) {
                $stale[] = $timeframe->code . ' (none)';
                $ages[$timeframe->code] = null;
                continue;
            }

            $age = $now->getTimestamp() - $candle->closeTime->getTimestamp();
            $ages[$timeframe->code] = $age;

            if ($age > $timeframe->seconds() * 2) {
                $stale[] = sprintf('%s (%s)', $timeframe->code, $this->duration($age));
            }
        }

        // A warning, never a critical: the market closes at the weekend, and an
        // H1 series is legitimately fifty hours old on a Sunday. Paging someone
        // for that is how alerts get muted.
        return $stale === []
            ? HealthReport::ok('market_data', 'Market data', 'All timeframes current.', ['age_seconds' => $ages])
            : HealthReport::warning(
                'market_data',
                'Market data',
                'Behind: ' . implode(', ', $stale) . '. Expected outside market hours.',
                ['age_seconds' => $ages]
            );
    }

    private function checkPriceFeed(): HealthReport
    {
        $instruments = $this->reference->activeInstruments();

        if ($instruments === []) {
            return HealthReport::unknown('price_feed', 'Price feed', 'No active instrument.');
        }

        $snapshot = $this->snapshots->latest((int) $instruments[0]['id']);

        if ($snapshot === null) {
            return HealthReport::warning('price_feed', 'Price feed', 'No quote has been captured yet.');
        }

        $age = $snapshot->ageSeconds($this->clock->now());
        $metrics = ['age_seconds' => $age, 'price' => $snapshot->price];

        return match (true) {
            $age > 1800 => HealthReport::critical('price_feed', 'Price feed', sprintf('Last quote %s old.', $this->duration($age)), $metrics),
            $age > 300  => HealthReport::warning('price_feed', 'Price feed', sprintf('Last quote %s old.', $this->duration($age)), $metrics),
            default     => HealthReport::ok('price_feed', 'Price feed', sprintf('Last quote %s old.', $this->duration($age)), $metrics),
        };
    }

    private function checkCalendar(): HealthReport
    {
        $latest = $this->database->scalar(
            'SELECT MAX(last_seen_at) FROM economic_events WHERE retired_at IS NULL'
        );

        if ($latest === null) {
            return HealthReport::warning('calendar', 'Economic calendar', 'No events imported.');
        }

        $age = $this->clock->now()->getTimestamp()
            - (new DateTimeImmutable((string) $latest, new DateTimeZone('UTC')))->getTimestamp();

        // Six hours behind means the import is broken — and a broken import
        // means the news filter is silently passing everything, which is worse
        // than an empty calendar because it looks like it is working.
        return $age > 21600
            ? HealthReport::warning('calendar', 'Economic calendar', sprintf('Last import %s ago — the news filter may be passing everything.', $this->duration($age)), ['age_seconds' => $age])
            : HealthReport::ok('calendar', 'Economic calendar', sprintf('Last import %s ago.', $this->duration($age)), ['age_seconds' => $age]);
    }

    /**
     * Provider health by recent SUCCESS RATE, not by budget.
     *
     * Budget exhaustion is a planned condition the gate already handles. A
     * provider answering with errors is not: nothing stops it, the calls still
     * count against the quota, and the data quietly stops arriving.
     */
    private function checkApiProviders(): HealthReport
    {
        $now = $this->clock->now();
        $rows = $this->operations->providerUsage($now);

        $degraded = [];
        $metrics = [];
        $worst = HealthStatus::Ok;

        foreach ($rows as $row) {
            if ((int) $row['is_active'] !== 1) {
                continue;
            }

            $code = (string) $row['code'];
            $failures = (int) $row['failures_last_hour'];
            $percentUsed = $row['daily_limit'] === null || (int) $row['daily_limit'] === 0
                ? null
                : round(((int) $row['credits_today'] / (int) $row['daily_limit']) * 100, 1);

            $metrics[$code] = [
                'failures_last_hour' => $failures,
                'percent_used'       => $percentUsed,
            ];

            $status = match (true) {
                $percentUsed !== null && $percentUsed >= 95 => HealthStatus::Critical,
                $failures >= 10                             => HealthStatus::Critical,
                $percentUsed !== null && $percentUsed >= 80 => HealthStatus::Warning,
                $failures > 0                               => HealthStatus::Warning,
                default                                     => HealthStatus::Ok,
            };

            if ($status->isDegraded()) {
                $degraded[] = $percentUsed !== null && $percentUsed >= 80
                    ? sprintf('%s at %.0f%% of quota', $code, $percentUsed)
                    : sprintf('%s %d failure(s) in the last hour', $code, $failures);
            }

            $worst = HealthStatus::worst([$worst, $status]);
        }

        if ($metrics === []) {
            return HealthReport::unknown('api_providers', 'API providers', 'No active provider configured.');
        }

        return new HealthReport(
            'api_providers',
            'API providers',
            $worst,
            $degraded === [] ? 'All providers healthy.' : implode('; ', $degraded) . '.',
            $metrics
        );
    }

    /**
     * Delivery health: reachability and queue movement.
     *
     * Depth alone cannot distinguish a busy queue from a stopped one. Forty
     * pending that drain every minute is healthy; two pending where the older
     * has waited an hour means the drain cron has died.
     */
    private function checkTelegram(): HealthReport
    {
        $stats = $this->telegram->queueStats($this->clock->now());
        $oldest = $stats['oldest_pending_seconds'];

        if (!$this->telegramClient->isConfigured()) {
            return HealthReport::warning(
                'telegram',
                'Telegram delivery',
                sprintf('No bot token configured — %d message(s) will queue but never send.', $stats['pending']),
                $stats
            );
        }

        $message = sprintf(
            '%d pending, %d failed, %d dead%s.',
            $stats['pending'],
            $stats['failed'],
            $stats['dead'],
            $oldest === null ? '' : sprintf('; oldest waiting %s', $this->duration((int) $oldest))
        );

        return match (true) {
            (int) $stats['dead'] > 0                => HealthReport::critical('telegram', 'Telegram delivery', $message, $stats),
            $oldest !== null && (int) $oldest > 900 => HealthReport::critical('telegram', 'Telegram delivery', $message . ' The drain task may have stopped.', $stats),
            (int) $stats['failed'] > 0              => HealthReport::warning('telegram', 'Telegram delivery', $message, $stats),
            $oldest !== null && (int) $oldest > 300 => HealthReport::warning('telegram', 'Telegram delivery', $message, $stats),
            default                                 => HealthReport::ok('telegram', 'Telegram delivery', $message, $stats),
        };
    }

    /**
     * Error rate over the last hour (docs/01 §11).
     *
     * A rising error rate is the earliest signal that something has broken,
     * and it usually appears before any individual component check trips.
     */
    private function checkErrorRate(): HealthReport
    {
        $since = $this->clock->now()->modify('-1 hour')->format('Y-m-d H:i:s.v');

        $errors = (int) $this->database->scalar(
            "SELECT COUNT(*) FROM system_logs
             WHERE created_at >= ? AND level IN ('error', 'critical', 'alert', 'emergency')",
            [$since]
        );

        $metrics = ['errors_last_hour' => $errors];

        return match (true) {
            $errors >= 50 => HealthReport::critical('error_rate', 'Error rate', sprintf('%d errors logged in the last hour.', $errors), $metrics),
            $errors >= 10 => HealthReport::warning('error_rate', 'Error rate', sprintf('%d errors logged in the last hour.', $errors), $metrics),
            default       => HealthReport::ok('error_rate', 'Error rate', sprintf('%d errors in the last hour.', $errors), $metrics),
        };
    }

    /**
     * Writable storage and free space.
     *
     * A full disk on shared hosting presents as a cascade of unrelated-looking
     * failures — sessions not saving, logs truncating, backups silently empty —
     * so it is worth naming directly rather than diagnosing five times.
     */
    private function checkStorage(): HealthReport
    {
        $unwritable = [];

        foreach (['logs', 'cache', 'locks', 'backups', 'tmp'] as $directory) {
            $path = $this->storagePath . '/' . $directory;

            if (!is_dir($path) || !is_writable($path)) {
                $unwritable[] = $directory;
            }
        }

        $free = @disk_free_space($this->storagePath);
        $total = @disk_total_space($this->storagePath);
        $percentFree = is_float($free) && is_float($total) && $total > 0
            ? round(($free / $total) * 100, 1)
            : null;

        $metrics = [
            'unwritable'   => $unwritable,
            'free_bytes'   => is_float($free) ? (int) $free : null,
            'percent_free' => $percentFree,
        ];

        if ($unwritable !== []) {
            return HealthReport::critical('storage', 'Storage', 'Not writable: ' . implode(', ', $unwritable) . '.', $metrics);
        }

        return match (true) {
            $percentFree !== null && $percentFree < 5  => HealthReport::critical('storage', 'Storage', sprintf('Only %.1f%% free.', $percentFree), $metrics),
            $percentFree !== null && $percentFree < 15 => HealthReport::warning('storage', 'Storage', sprintf('%.1f%% free.', $percentFree), $metrics),
            default                                    => HealthReport::ok('storage', 'Storage', $percentFree === null ? 'Writable.' : sprintf('Writable, %.1f%% free.', $percentFree), $metrics),
        };
    }

    /**
     * Log directory size (docs/01 §11).
     *
     * On shared hosting an unbounded log directory eventually exhausts the
     * account quota, which takes the whole site down rather than just logging.
     * The cleanup task prunes; this notices when pruning has stopped.
     */
    private function checkLogs(): HealthReport
    {
        if (!is_dir($this->logPath)) {
            return HealthReport::unknown('logs', 'Log directory', 'The log directory does not exist.');
        }

        $bytes = 0;
        $files = 0;

        foreach (glob($this->logPath . '/*.log') ?: [] as $file) {
            $bytes += (int) @filesize($file);
            $files++;
        }

        $megabytes = round($bytes / 1_048_576, 1);
        $metrics = ['files' => $files, 'bytes' => $bytes, 'megabytes' => $megabytes];

        return match (true) {
            $megabytes > 1024 => HealthReport::critical('logs', 'Log directory', sprintf('%s MB across %d file(s) — pruning has stopped.', $megabytes, $files), $metrics),
            $megabytes > 256  => HealthReport::warning('logs', 'Log directory', sprintf('%s MB across %d file(s).', $megabytes, $files), $metrics),
            default           => HealthReport::ok('logs', 'Log directory', sprintf('%s MB across %d file(s).', $megabytes, $files), $metrics),
        };
    }

    /**
     * Name the first few and count the rest.
     *
     * On a fresh install with no cron entry yet, EVERY task is overdue — and
     * the full list overflowed the message column, which took the health check
     * down with a PDOException on the one install where it mattered most. It
     * is also unreadable: a Telegram alert listing eleven task codes with ages
     * tells an operator less than "four of these, and there are more".
     *
     * @param list<string> $items
     */
    private function summarise(array $items, int $show = 4): string
    {
        if (count($items) <= $show) {
            return implode(', ', $items);
        }

        return implode(', ', array_slice($items, 0, $show))
            . sprintf(' and %d more', count($items) - $show);
    }

    /** Coarse on purpose: the operational question is minutes, not seconds. */
    private function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 60    => $seconds . 's',
            $seconds < 3600  => intdiv($seconds, 60) . 'm',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h',
            default          => intdiv($seconds, 86400) . 'd',
        };
    }
}
