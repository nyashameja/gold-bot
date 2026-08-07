<?php

declare(strict_types=1);

namespace Paragon\Core\Lock;

use Paragon\Core\Database;
use Throwable;

/**
 * Mutual exclusion via MySQL GET_LOCK() / RELEASE_LOCK() (ADR-09).
 *
 * Chosen over PID files and flock because the lock is bound to the database
 * connection: if the PHP process is killed, times out, or dies on a fatal
 * error, MySQL drops the connection and releases the lock automatically.
 * A PID file in the same situation survives, and the task never runs again
 * until a human notices — which on a signal platform could be days.
 *
 * Caveat worth knowing: on MySQL 5.6 and earlier a connection could hold only
 * one named lock at a time. 5.7+ and MariaDB 10.0.2+ allow many, which is why
 * docs/02 targets MySQL 8 / MariaDB 10.6+.
 */
final class MySqlNamedLock implements LockInterface
{
    /**
     * MySQL truncates lock names beyond 64 characters, and two tasks whose
     * names collide after truncation would silently share a lock. Names are
     * hashed past that length instead.
     */
    private const MAX_NAME_LENGTH = 64;

    /** @var array<string,true> Locks held by this process. */
    private array $held = [];

    public function __construct(
        private readonly Database $database,
        private readonly string $prefix = 'goldbot'
    ) {
    }

    public function acquire(string $name, int $timeoutSeconds = 0): bool
    {
        $key = $this->key($name);

        // GET_LOCK is re-entrant per connection and increments a counter, so a
        // second acquire would need a second release. Guarding here keeps
        // acquire/release symmetrical for callers.
        if (isset($this->held[$key])) {
            return true;
        }

        $result = $this->database->scalar('SELECT GET_LOCK(?, ?)', [$key, $timeoutSeconds]);

        // 1 = acquired, 0 = timed out, NULL = error (e.g. connection killed).
        if ((int) $result !== 1) {
            return false;
        }

        $this->held[$key] = true;

        return true;
    }

    public function release(string $name): bool
    {
        $key = $this->key($name);

        if (!isset($this->held[$key])) {
            return false;
        }

        unset($this->held[$key]);

        $result = $this->database->scalar('SELECT RELEASE_LOCK(?)', [$key]);

        return (int) $result === 1;
    }

    public function isHeld(string $name): bool
    {
        $key = $this->key($name);

        // IS_FREE_LOCK returns 1 when nobody holds it, 0 when someone does.
        $result = $this->database->scalar('SELECT IS_FREE_LOCK(?)', [$key]);

        return (int) $result === 0;
    }

    public function withLock(string $name, callable $callback, int $timeoutSeconds = 0): mixed
    {
        if (!$this->acquire($name, $timeoutSeconds)) {
            return null;
        }

        try {
            return $callback();
        } finally {
            // Released even if the callback throws. The connection would drop
            // the lock anyway on process death, but a long-lived CLI process
            // running several tasks must not leak it between them.
            $this->release($name);
        }
    }

    /**
     * Release everything this process holds. Called from the shutdown handler
     * as a belt-and-braces measure.
     */
    public function releaseAll(): void
    {
        foreach (array_keys($this->held) as $key) {
            unset($this->held[$key]);

            try {
                $this->database->scalar('SELECT RELEASE_LOCK(?)', [$key]);
            } catch (Throwable) {
                // The connection is already gone, which means MySQL has
                // released the lock for us. Nothing useful to do here.
            }
        }
    }

    private function key(string $name): string
    {
        $key = $this->prefix . ':' . $name;

        if (strlen($key) <= self::MAX_NAME_LENGTH) {
            return $key;
        }

        return $this->prefix . ':' . hash('xxh128', $name);
    }
}
