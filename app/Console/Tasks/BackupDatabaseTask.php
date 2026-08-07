<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Services\Backup\BackupService;
use Paragon\Core\Logging\LoggerInterface;
use Throwable;

/**
 * The nightly database backup (docs/01 §12).
 *
 * A backup failure IS a task failure — unlike a degraded health check, which
 * reports a problem the task can do nothing about. Here the task's own job did
 * not happen, and the scheduler's consecutive-failure counter is exactly the
 * mechanism that should notice: a backup that has been silently failing for
 * three weeks is discovered at the worst possible moment.
 */
final class BackupDatabaseTask implements TaskInterface
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly SettingsRepositoryInterface $settings,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(): TaskResult
    {
        if (!(bool) $this->settings->get('backup.enabled', true)) {
            return TaskResult::skipped('Backups are disabled in settings.');
        }

        try {
            $backup = $this->backups->create();
            $removed = $this->backups->rotate();
        } catch (Throwable $e) {
            $this->logger->error('Database backup failed', [
                'event'     => 'backup.failed',
                'exception' => $e,
            ]);

            return TaskResult::failed($e->getMessage());
        }

        return TaskResult::success(1, sprintf(
            '%s, %s%s',
            basename($backup['file']),
            $this->humanBytes($backup['bytes']),
            $removed === [] ? '' : sprintf(', %d old backup(s) rotated out', count($removed))
        ));
    }

    private function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => round($bytes / 1_073_741_824, 2) . ' GB',
            $bytes >= 1_048_576     => round($bytes / 1_048_576, 1) . ' MB',
            $bytes >= 1024          => round($bytes / 1024) . ' KB',
            default                 => $bytes . ' B',
        };
    }
}
