<?php

declare(strict_types=1);

namespace GoldBot\Console;

use GoldBot\Console\Tasks\TaskInterface;
use GoldBot\Console\Tasks\TaskResult;
use Paragon\Core\Clock\ClockInterface;
use Paragon\Core\Container;
use Paragon\Core\Database;
use Paragon\Core\Lock\LockInterface;
use Paragon\Core\Logging\LoggerInterface;
use Throwable;

/**
 * Runs due scheduled tasks (ADR-08).
 *
 * A single cPanel cron entry invokes this every minute; the schedule lives in
 * the scheduled_tasks table, so changing a cadence is a settings edit rather
 * than a cPanel change. The System Health page can then compare what *should*
 * have run against what did — the only way to notice a task that stopped
 * silently, since a cron that never fires produces no errors at all.
 */
final class TaskDispatcher
{
    public function __construct(
        private readonly Container $container,
        private readonly Database $database,
        private readonly LockInterface $lock,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run every task that is due.
     *
     * @return array<string,TaskResult> Task code => result.
     */
    public function runDue(): array
    {
        $results = [];

        foreach ($this->dueTasks() as $task) {
            $results[$task['code']] = $this->runTask($task);
        }

        return $results;
    }

    /**
     * Run one task by code regardless of whether it is due.
     *
     * Used by the manual trigger on the dashboard and by `cron/run.php task`.
     */
    public function runOne(string $code, bool $ignoreLock = false): TaskResult
    {
        $task = $this->database->selectOne('SELECT * FROM scheduled_tasks WHERE code = ?', [$code]);

        if ($task === null) {
            return TaskResult::skipped("No scheduled task named [{$code}].");
        }

        return $this->runTask($task, $ignoreLock);
    }

    /** @param array<string,mixed> $task */
    private function runTask(array $task, bool $ignoreLock = false): TaskResult
    {
        $code = (string) $task['code'];
        $lockName = 'task:' . $code;

        if (!$ignoreLock && !$this->lock->acquire($lockName, (int) $task['lock_timeout_seconds'])) {
            // Healthy, not an error: the previous run is still going. Recorded
            // so a chronically overrunning task is visible rather than silent.
            $this->recordRun($task, TaskResult::skippedLocked(), 0);

            return TaskResult::skippedLocked();
        }

        $runId = $this->startRun($task);
        $startedAt = microtime(true);

        try {
            $handler = $this->container->get((string) $task['handler_class']);

            if (!$handler instanceof TaskInterface) {
                $result = TaskResult::failed(
                    sprintf('[%s] does not implement TaskInterface.', (string) $task['handler_class'])
                );
            } else {
                $this->logger->info('Task started', ['event' => 'cron.started', 'task' => $code]);
                $result = $handler->run();
            }
        } catch (Throwable $e) {
            // A task must never take down the dispatcher: the remaining due
            // tasks still need to run.
            $this->logger->error('Task threw', [
                'event'     => 'cron.failed',
                'task'      => $code,
                'exception' => $e,
            ]);

            $result = TaskResult::failed($e->getMessage());
        } finally {
            if (!$ignoreLock) {
                $this->lock->release($lockName);
            }
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->finishRun($runId, $result, $durationMs);
        $this->updateTaskState($task, $result);

        $this->logger->info('Task finished', [
            'event'       => 'cron.finished',
            'task'        => $code,
            'status'      => $result->status,
            'items'       => $result->itemsProcessed,
            'duration_ms' => $durationMs,
        ]);

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function dueTasks(): array
    {
        return $this->database->select(
            'SELECT * FROM scheduled_tasks
             WHERE is_enabled = 1
               AND (next_due_at IS NULL OR next_due_at <= ?)
             ORDER BY sort_order, id',
            [$this->clock->now()->format('Y-m-d H:i:s')]
        );
    }

    /** @param array<string,mixed> $task */
    private function startRun(array $task): int
    {
        return $this->database->insert('task_runs', [
            'task_id'    => (int) $task['id'],
            'status'     => 'RUNNING',
            'started_at' => $this->clock->now()->format('Y-m-d H:i:s.v'),
        ]);
    }

    private function finishRun(int $runId, TaskResult $result, int $durationMs): void
    {
        $this->database->run(
            'UPDATE task_runs
             SET status = ?, finished_at = ?, duration_ms = ?, items_processed = ?, output = ?, error_message = ?
             WHERE id = ?',
            [
                $result->status,
                $this->clock->now()->format('Y-m-d H:i:s.v'),
                $durationMs,
                $result->itemsProcessed,
                substr($result->output, 0, 500),
                $result->errorMessage,
                $runId,
            ]
        );
    }

    /** A skipped run is recorded without a RUNNING row in between. */
    private function recordRun(array $task, TaskResult $result, int $durationMs): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.v');

        $this->database->insert('task_runs', [
            'task_id'     => (int) $task['id'],
            'status'      => $result->status,
            'started_at'  => $now,
            'finished_at' => $now,
            'duration_ms' => $durationMs,
            'output'      => $result->output,
        ]);
    }

    /**
     * @param array<string,mixed> $task
     */
    private function updateTaskState(array $task, TaskResult $result): void
    {
        $now = $this->clock->now();
        $cadence = max(1, (int) $task['cadence_minutes']);

        // Next due is computed from now rather than from the previous due
        // time, so a task that overran does not immediately fire a backlog of
        // missed slots the moment it recovers.
        $nextDue = $now->modify(sprintf('+%d minutes', $cadence));

        $this->database->run(
            'UPDATE scheduled_tasks
             SET last_run_at = ?,
                 last_success_at = IF(? = 1, ?, last_success_at),
                 consecutive_failures = IF(? = 1, 0, consecutive_failures + 1),
                 next_due_at = ?
             WHERE id = ?',
            [
                $now->format('Y-m-d H:i:s'),
                $result->isSuccess() ? 1 : 0,
                $now->format('Y-m-d H:i:s'),
                $result->countsAsFailure() ? 0 : 1,
                $nextDue->format('Y-m-d H:i:s'),
                (int) $task['id'],
            ]
        );
    }
}
