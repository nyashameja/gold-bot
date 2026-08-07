<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Services\Performance\SnapshotBuilder;
use GoldBot\Services\Signals\SignalLifecycleService;
use Paragon\Core\Logging\LoggerInterface;
use Throwable;

/**
 * Advances open signals: entry fills, targets, stops and expiry.
 *
 * Works from stored candles, so it costs no API budget and can replay a gap
 * after an outage rather than losing the transitions that happened during it.
 *
 * When something closes, the affected performance periods are rebuilt here.
 * The orchestration lives in the task rather than in the lifecycle service:
 * advancing a signal's state and maintaining a reporting projection are
 * different jobs, and wiring the second into the first would mean a strategy
 * test could not run without a snapshot table (docs/01 §4).
 */
final class TrackSignalLifecycleTask implements TaskInterface
{
    public function __construct(
        private readonly SignalLifecycleService $lifecycle,
        private readonly SnapshotBuilder $snapshots,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(): TaskResult
    {
        try {
            $result = $this->lifecycle->track();
        } catch (Throwable $e) {
            $this->logger->error('Signal lifecycle tracking failed', [
                'event'     => 'signal.tracking_failed',
                'exception' => $e,
            ]);

            return TaskResult::failed($e->getMessage());
        }

        $refreshed = $this->refreshSnapshots($result['closed']);

        return TaskResult::success(
            $result['checked'],
            sprintf(
                '%d open, %d activated, %d target(s), %d stopped, %d expired%s',
                $result['checked'],
                $result['activated'],
                $result['targets'],
                $result['stopped'],
                $result['expired'],
                $refreshed === 0 ? '' : sprintf(', %d snapshot(s) refreshed', $refreshed)
            )
        );
    }

    /**
     * Rebuild the periods containing each close.
     *
     * A failure here must not fail the task. The signal transitions are
     * already committed and are the record; a stale snapshot is a reporting
     * inconvenience that the nightly rebuild corrects on its own, whereas
     * reporting the tracking run as failed would make the scheduler retry work
     * that already succeeded.
     *
     * @param list<\DateTimeImmutable> $closed
     */
    private function refreshSnapshots(array $closed): int
    {
        if ($closed === []) {
            return 0;
        }

        // Distinct days only: ten signals closing on the same afternoon are
        // one rebuild, not ten.
        $seen = [];
        $written = 0;

        foreach ($closed as $closedAt) {
            $day = $closedAt->format('Y-m-d');

            if (isset($seen[$day])) {
                continue;
            }

            $seen[$day] = true;

            try {
                $written += $this->snapshots->rebuildFor($closedAt);
            } catch (Throwable $e) {
                $this->logger->error('Performance refresh failed after a close', [
                    'event'     => 'performance.refresh_failed',
                    'closed_at' => $closedAt->format(DATE_ATOM),
                    'exception' => $e,
                ]);
            }
        }

        return $written;
    }
}
