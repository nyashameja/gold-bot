<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Services\Performance\SnapshotBuilder;
use Paragon\Core\Logging\LoggerInterface;
use Throwable;

/**
 * The nightly performance rebuild.
 *
 * Snapshots are already refreshed the moment a signal closes, so this is not
 * how the numbers stay current — it is how they stay CORRECT. Anything that
 * changed a closed signal without going through the close path (a manual
 * correction, a restored backup, a bug fixed after the fact) leaves the
 * incremental refresh with no reason to have run. A full rebuild from the
 * source records converges regardless, which is the property that makes the
 * projection trustworthy rather than merely fast.
 *
 * It costs no API budget: everything it reads is local.
 */
final class RebuildPerformanceTask implements TaskInterface
{
    public function __construct(
        private readonly SnapshotBuilder $builder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(): TaskResult
    {
        try {
            $result = $this->builder->rebuildAll();
        } catch (Throwable $e) {
            $this->logger->error('Performance rebuild failed', [
                'event'     => 'performance.rebuild_failed',
                'exception' => $e,
            ]);

            return TaskResult::failed($e->getMessage());
        }

        if ($result['snapshots'] === 0) {
            return TaskResult::skipped('No closed signals to measure yet.');
        }

        return TaskResult::success(
            $result['snapshots'],
            sprintf(
                '%d snapshot(s) across %d period(s), %s to %s',
                $result['snapshots'],
                $result['periods'],
                substr((string) $result['from'], 0, 10),
                substr((string) $result['to'], 0, 10)
            )
        );
    }
}
