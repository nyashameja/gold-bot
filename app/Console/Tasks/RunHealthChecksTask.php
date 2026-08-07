<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Domain\Health\HealthStatus;
use GoldBot\Services\Health\HealthMonitor;
use Paragon\Core\Logging\LoggerInterface;
use Throwable;

/**
 * Records component health and alerts on transitions (docs/01 §11).
 *
 * There is an obvious circularity here — the task that detects a dead
 * scheduler is itself run by the scheduler — and it is not a flaw in this
 * task, it is why the System Health page computes the same checks live. The
 * cron gives history; the page gives an answer even when the cron is the thing
 * that died.
 *
 * Reads local data only, so it costs no API budget and stays cheap enough to
 * run when the platform is already in trouble.
 */
final class RunHealthChecksTask implements TaskInterface
{
    public function __construct(
        private readonly HealthMonitor $monitor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(): TaskResult
    {
        try {
            $result = $this->monitor->run();
        } catch (Throwable $e) {
            $this->logger->error('Health checks failed', [
                'event'     => 'health.run_failed',
                'exception' => $e,
            ]);

            return TaskResult::failed($e->getMessage());
        }

        /** @var HealthStatus $overall */
        $overall = $result['overall'];
        $degraded = array_filter(
            $result['reports'],
            static fn ($report): bool => $report->status->isDegraded()
        );

        $summary = sprintf(
            '%s — %d checks, %d degraded%s',
            $overall->value,
            count($result['reports']),
            count($degraded),
            $result['transitions'] === []
                ? ''
                : sprintf(', %d transition(s), %d alert(s) queued', count($result['transitions']), $result['alerts'])
        );

        // A degraded platform is not a failed TASK. Reporting it as failed
        // would mark the health checker itself as broken in task_runs and
        // trigger the scheduler's own failure handling for a condition it can
        // do nothing about.
        return TaskResult::success(count($result['reports']), $summary);
    }
}
