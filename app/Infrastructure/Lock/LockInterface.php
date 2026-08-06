<?php

declare(strict_types=1);

namespace GoldBot\Infrastructure\Lock;

/**
 * Mutual exclusion for scheduled tasks (ADR-09).
 *
 * The dispatcher acquires a named lock before running a task so that a slow
 * run cannot overlap with the next minute's invocation.
 */
interface LockInterface
{
    /**
     * Try to acquire the named lock, waiting at most $timeoutSeconds.
     *
     * Returns false rather than throwing when the lock is held: a task that
     * is already running is a normal condition, recorded as SKIPPED_LOCKED,
     * not an error (docs/02 §9).
     */
    public function acquire(string $name, int $timeoutSeconds = 0): bool;

    public function release(string $name): bool;

    public function isHeld(string $name): bool;

    /**
     * Run a callback only if the lock can be acquired.
     *
     * @template T
     * @param callable():T $callback
     * @return T|null Null when the lock was unavailable.
     */
    public function withLock(string $name, callable $callback, int $timeoutSeconds = 0): mixed;
}
