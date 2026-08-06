<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use GoldBot\Console\TaskDispatcher;
use GoldBot\Console\Tasks\TaskInterface;
use GoldBot\Console\Tasks\TaskResult;
use GoldBot\Infrastructure\Lock\MySqlNamedLock;
use RuntimeException;

/** A task whose behaviour each test dictates. */
final class ScriptedTask implements TaskInterface
{
    public static int $runCount = 0;

    /** @var callable():TaskResult */
    public static $behaviour;

    public static function reset(): void
    {
        self::$runCount = 0;
        self::$behaviour = static fn (): TaskResult => TaskResult::success(1, 'ok');
    }

    public function run(): TaskResult
    {
        self::$runCount++;

        return (self::$behaviour)();
    }
}

/**
 * The scheduler (ADR-08). Its correctness is what stops two copies of an
 * ingest task racing, and what makes a silently dead cron detectable.
 */
final class TaskDispatcherTest extends IntegrationTestCase
{
    private const CODE = 'test.scripted';

    private TaskDispatcher $dispatcher;

    private int $taskId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('scheduled_tasks')) {
            self::markTestSkipped('Operations schema not migrated.');
        }

        ScriptedTask::reset();

        $this->app->container()->singleton(ScriptedTask::class, static fn (): ScriptedTask => new ScriptedTask());
        $this->dispatcher = $this->app->container()->get(TaskDispatcher::class);

        $this->db->run('DELETE FROM scheduled_tasks WHERE code = ?', [self::CODE]);

        // runDue() runs everything that is due — including the real seeded
        // tasks, which would make live API calls and rewrite production
        // schedule state. Disable them for the duration and restore after.
        $this->db->run('UPDATE scheduled_tasks SET is_enabled = 0 WHERE is_enabled = 1');

        $this->taskId = $this->db->insert('scheduled_tasks', [
            'code'            => self::CODE,
            'name'            => 'Scripted test task',
            'handler_class'   => ScriptedTask::class,
            'cadence_minutes' => 5,
            'is_enabled'      => 1,
            'sort_order'      => 999,
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->run('DELETE FROM task_runs WHERE task_id = ?', [$this->taskId]);
        $this->db->run('DELETE FROM scheduled_tasks WHERE id = ?', [$this->taskId]);
        $this->db->run('UPDATE scheduled_tasks SET is_enabled = 1');

        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function task(): array
    {
        return (array) $this->db->selectOne('SELECT * FROM scheduled_tasks WHERE id = ?', [$this->taskId]);
    }

    /** @return list<array<string,mixed>> */
    private function runs(): array
    {
        return $this->db->select('SELECT * FROM task_runs WHERE task_id = ? ORDER BY id', [$this->taskId]);
    }

    public function test_a_due_task_runs_and_is_recorded(): void
    {
        $results = $this->dispatcher->runDue();

        self::assertArrayHasKey(self::CODE, $results);
        self::assertTrue($results[self::CODE]->isSuccess());
        self::assertSame(1, ScriptedTask::$runCount);

        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame('SUCCESS', $runs[0]['status']);
        self::assertNotNull($runs[0]['finished_at']);
        self::assertSame(1, (int) $runs[0]['items_processed']);
    }

    public function test_next_due_is_set_from_the_cadence_so_it_does_not_immediately_rerun(): void
    {
        $this->dispatcher->runDue();

        $task = $this->task();
        self::assertNotNull($task['next_due_at']);
        self::assertNotNull($task['last_success_at']);

        ScriptedTask::$runCount = 0;
        $this->dispatcher->runDue();

        self::assertSame(0, ScriptedTask::$runCount, 'A task that just ran is not due again.');
    }

    public function test_a_disabled_task_does_not_run(): void
    {
        $this->db->run('UPDATE scheduled_tasks SET is_enabled = 0 WHERE id = ?', [$this->taskId]);

        $this->dispatcher->runDue();

        self::assertSame(0, ScriptedTask::$runCount);
    }

    /**
     * The behaviour the whole dispatcher exists for: a slow run must not
     * overlap with the next minute's invocation.
     */
    public function test_a_locked_task_is_skipped_rather_than_run_twice(): void
    {
        $otherConnection = $this->separateConnection();
        $holder = new MySqlNamedLock($otherConnection);

        self::assertTrue($holder->acquire('task:' . self::CODE));

        $results = $this->dispatcher->runDue();

        self::assertSame(TaskResult::SKIPPED_LOCKED, $results[self::CODE]->status);
        self::assertSame(0, ScriptedTask::$runCount, 'The handler must not have been invoked.');

        // Recorded, so a chronically overrunning task is visible rather than
        // silent — but it is not a failure.
        $runs = $this->runs();
        self::assertCount(1, $runs);
        self::assertSame('SKIPPED_LOCKED', $runs[0]['status']);

        $holder->release('task:' . self::CODE);
        $otherConnection->disconnect();
    }

    public function test_a_skipped_lock_does_not_count_as_a_failure(): void
    {
        $otherConnection = $this->separateConnection();
        $holder = new MySqlNamedLock($otherConnection);
        $holder->acquire('task:' . self::CODE);

        $this->dispatcher->runDue();

        self::assertSame(0, (int) $this->task()['consecutive_failures']);

        $holder->release('task:' . self::CODE);
        $otherConnection->disconnect();
    }

    /**
     * A task must never take down the dispatcher — the remaining due tasks
     * still need to run.
     */
    public function test_a_throwing_task_is_caught_and_recorded_as_failed(): void
    {
        ScriptedTask::$behaviour = static fn (): TaskResult => throw new RuntimeException('provider exploded');

        $results = $this->dispatcher->runDue();

        self::assertSame(TaskResult::FAILED, $results[self::CODE]->status);
        self::assertStringContainsString('provider exploded', (string) $results[self::CODE]->errorMessage);

        $runs = $this->runs();
        self::assertSame('FAILED', $runs[0]['status']);
        self::assertStringContainsString('provider exploded', (string) $runs[0]['error_message']);
    }

    public function test_consecutive_failures_accumulate_and_reset_on_success(): void
    {
        ScriptedTask::$behaviour = static fn (): TaskResult => TaskResult::failed('nope');

        $this->dispatcher->runOne(self::CODE, ignoreLock: true);
        $this->dispatcher->runOne(self::CODE, ignoreLock: true);

        self::assertSame(2, (int) $this->task()['consecutive_failures']);
        self::assertNull($this->task()['last_success_at'], 'A failure must not stamp last_success_at.');

        ScriptedTask::$behaviour = static fn (): TaskResult => TaskResult::success(3, 'recovered');
        $this->dispatcher->runOne(self::CODE, ignoreLock: true);

        self::assertSame(0, (int) $this->task()['consecutive_failures']);
        self::assertNotNull($this->task()['last_success_at']);
    }

    /**
     * SKIPPED_BUDGET is a warning, not an error — the task did nothing wrong,
     * so it must not inflate the failure counter that drives health alerts.
     */
    public function test_a_budget_skip_does_not_count_as_a_failure(): void
    {
        ScriptedTask::$behaviour = static fn (): TaskResult => TaskResult::skippedBudget();

        $this->dispatcher->runOne(self::CODE, ignoreLock: true);

        self::assertSame(0, (int) $this->task()['consecutive_failures']);
        self::assertSame('SKIPPED_BUDGET', $this->runs()[0]['status']);
    }

    public function test_run_one_ignores_whether_the_task_is_due(): void
    {
        $this->db->run(
            'UPDATE scheduled_tasks SET next_due_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = ?',
            [$this->taskId]
        );

        $result = $this->dispatcher->runOne(self::CODE, ignoreLock: true);

        self::assertTrue($result->isSuccess());
        self::assertSame(1, ScriptedTask::$runCount);
    }

    public function test_an_unknown_task_code_is_reported_not_thrown(): void
    {
        $result = $this->dispatcher->runOne('no.such.task');

        self::assertSame(TaskResult::SKIPPED, $result->status);
        self::assertStringContainsString('no.such.task', $result->output);
    }

    /** The lock must be released even when the handler throws. */
    public function test_the_lock_is_released_after_a_failure(): void
    {
        ScriptedTask::$behaviour = static fn (): TaskResult => throw new RuntimeException('boom');

        $this->dispatcher->runDue();

        $otherConnection = $this->separateConnection();
        $probe = new MySqlNamedLock($otherConnection);

        self::assertTrue(
            $probe->acquire('task:' . self::CODE, 0),
            'A failed task must not leave its lock held.'
        );

        $probe->release('task:' . self::CODE);
        $otherConnection->disconnect();
    }
}
