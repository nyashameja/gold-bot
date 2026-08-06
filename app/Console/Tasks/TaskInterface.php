<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

/**
 * A unit of scheduled work.
 *
 * Tasks are resolved from the container by the class name stored in
 * scheduled_tasks.handler_class, so registering one is a row plus a binding
 * (ADR-08).
 *
 * A task must be safe to run twice: the dispatcher guarantees no two runs
 * overlap, but not that a run which died mid-way left nothing behind.
 */
interface TaskInterface
{
    public function run(): TaskResult;
}
