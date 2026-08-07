<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Core\Database;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Logging\FileLogger;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;

/**
 * Enforces the retention policy (docs/02 §10).
 *
 * Deliberately absent, and each for its own reason:
 *
 *   candles, candle_indicators  the asset — backtesting needs full history
 *   signals, signal_events      the permanent performance record
 *   audit_logs                  an audit trail that expires is not one
 *   performance_snapshots       rebuilt wholesale, never aged out
 *   economic_events             the ONLY archive that will ever exist, because
 *                               the upstream feed is a rolling window (ADR-15).
 *                               Pruning this is unrecoverable.
 *
 * On shared hosting an unbounded log directory eventually exhausts the account
 * quota, which takes the whole site down — not just logging.
 */
final class CleanupTask implements TaskInterface
{
    public function __construct(
        private readonly Database $database,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(): TaskResult
    {
        $deleted = 0;
        $report = [];

        $targets = [
            ['price_snapshots', 'captured_at',  'retention.price_snapshots_days', 30],
            ['api_usage_log',   'requested_at', 'retention.api_usage_days',       90],
            ['task_runs',       'started_at',   'retention.task_runs_days',       90],
            ['system_logs',     'created_at',   'retention.system_logs_days',     90],
            ['health_checks',   'checked_at',   'retention.health_checks_days',   30],
            ['strategy_runs',   'evaluated_at', 'retention.strategy_runs_days',   180],
            ['login_attempts',  'attempted_at', 'retention.login_attempts_days',  90],
        ];

        foreach ($targets as [$table, $column, $settingKey, $default]) {
            $days = (int) $this->settings->get($settingKey, $default);

            if ($days < 1) {
                continue; // 0 or negative disables pruning for that table.
            }

            $cutoff = $this->clock->now()->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s');

            // Deleted in bounded batches: a single unbounded DELETE over a
            // large table can hold locks long enough to stall ingest.
            $removed = 0;

            do {
                $batch = $this->database->run(
                    "DELETE FROM `{$table}` WHERE `{$column}` < ? LIMIT 5000",
                    [$cutoff]
                );

                $removed += $batch;
            } while ($batch === 5000);

            if ($removed > 0) {
                $report[] = sprintf('%s: %d', $table, $removed);
            }

            $deleted += $removed;
        }

        // Telegram: SENT messages age out, DEAD ones are kept (docs/02 §10).
        // A dead-lettered message is the evidence of a delivery problem, and
        // pruning it on a timer destroys the record of the thing that needs
        // investigating.
        $telegramDays = (int) $this->settings->get('retention.telegram_sent_days', 90);

        if ($telegramDays >= 1) {
            $cutoff = $this->clock->now()->modify(sprintf('-%d days', $telegramDays))->format('Y-m-d H:i:s');
            $removed = 0;

            do {
                $batch = $this->database->run(
                    "DELETE FROM telegram_messages WHERE status = 'SENT' AND sent_at < ? LIMIT 5000",
                    [$cutoff]
                );

                $removed += $batch;
            } while ($batch === 5000);

            if ($removed > 0) {
                $report[] = sprintf('telegram_messages: %d', $removed);
                $deleted += $removed;
            }
        }

        // Expired sessions, regardless of retention settings.
        $sessions = $this->database->run('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP()');

        if ($sessions > 0) {
            $report[] = sprintf('sessions: %d', $sessions);
            $deleted += $sessions;
        }

        $logFiles = 0;

        if ($this->logger instanceof FileLogger) {
            $logFiles = $this->logger->prune();

            if ($logFiles > 0) {
                $report[] = sprintf('log files: %d', $logFiles);
            }
        }

        return TaskResult::success(
            $deleted,
            $report === [] ? 'Nothing to prune.' : implode(', ', $report)
        );
    }
}
