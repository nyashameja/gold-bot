<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use Paragon\Core\Lock\MySqlNamedLock;
use RuntimeException;

/**
 * The scheduler's correctness rests entirely on these behaviours (ADR-09).
 */
final class MySqlNamedLockTest extends IntegrationTestCase
{
    private function lockName(): string
    {
        return 'test:' . bin2hex(random_bytes(4));
    }

    public function test_a_lock_can_be_acquired_and_released(): void
    {
        $lock = new MySqlNamedLock($this->db);
        $name = $this->lockName();

        self::assertTrue($lock->acquire($name));
        self::assertTrue($lock->isHeld($name));
        self::assertTrue($lock->release($name));
        self::assertFalse($lock->isHeld($name));
    }

    /**
     * The behaviour the whole dispatcher depends on: a task already running in
     * another process must not start a second time.
     */
    public function test_a_second_connection_cannot_acquire_a_held_lock(): void
    {
        $name = $this->lockName();

        $first = new MySqlNamedLock($this->db);
        $otherConnection = $this->separateConnection();
        $second = new MySqlNamedLock($otherConnection);

        self::assertTrue($first->acquire($name));
        self::assertFalse($second->acquire($name, 0), 'A held lock must not be acquirable elsewhere.');

        $first->release($name);

        self::assertTrue($second->acquire($name, 0), 'The lock must be available once released.');

        $second->release($name);
        $otherConnection->disconnect();
    }

    /**
     * The reason this implementation was chosen over PID files: a process that
     * dies must not leave the task permanently blocked. Dropping the
     * connection is how a killed PHP process looks to MySQL.
     */
    public function test_dropping_the_connection_releases_the_lock(): void
    {
        $name = $this->lockName();

        $dyingConnection = $this->separateConnection();
        $dying = new MySqlNamedLock($dyingConnection);

        self::assertTrue($dying->acquire($name));

        $observer = new MySqlNamedLock($this->db);
        self::assertFalse($observer->acquire($name, 0));

        // Simulate the process dying without any cleanup.
        $dyingConnection->disconnect();

        self::assertTrue(
            $observer->acquire($name, 2),
            'A lock held by a dead connection must become available again.'
        );

        $observer->release($name);
    }

    public function test_acquiring_twice_from_the_same_connection_is_idempotent(): void
    {
        $lock = new MySqlNamedLock($this->db);
        $name = $this->lockName();

        self::assertTrue($lock->acquire($name));
        self::assertTrue($lock->acquire($name), 'Re-acquiring must not deadlock or double-count.');

        // A single release must fully free it. If acquire() had incremented
        // MySQL's internal counter twice, this would leave it held.
        self::assertTrue($lock->release($name));

        $other = new MySqlNamedLock($this->separateConnection());
        self::assertTrue($other->acquire($name, 0), 'One release must fully free the lock.');
        $other->release($name);
    }

    public function test_with_lock_runs_the_callback_and_releases_afterwards(): void
    {
        $lock = new MySqlNamedLock($this->db);
        $name = $this->lockName();

        $result = $lock->withLock($name, static fn (): string => 'ran');

        self::assertSame('ran', $result);
        self::assertFalse($lock->isHeld($name));
    }

    public function test_with_lock_returns_null_when_the_lock_is_unavailable(): void
    {
        $name = $this->lockName();

        $holder = new MySqlNamedLock($this->separateConnection());
        $holder->acquire($name);

        $lock = new MySqlNamedLock($this->db);
        $ran = false;

        $result = $lock->withLock($name, static function () use (&$ran): string {
            $ran = true;

            return 'should not happen';
        });

        self::assertNull($result);
        self::assertFalse($ran, 'The callback must not run without the lock.');
    }

    public function test_the_lock_is_released_when_the_callback_throws(): void
    {
        $lock = new MySqlNamedLock($this->db);
        $name = $this->lockName();

        try {
            $lock->withLock($name, static fn () => throw new RuntimeException('task failed'));
            self::fail('The exception should have propagated.');
        } catch (RuntimeException $e) {
            self::assertSame('task failed', $e->getMessage());
        }

        self::assertFalse($lock->isHeld($name), 'A failed task must not leave its lock held.');
    }

    /**
     * MySQL truncates lock names past 64 characters, so two long task names
     * sharing a prefix would silently share one lock — and one task would
     * never run.
     */
    public function test_long_names_do_not_collide_after_truncation(): void
    {
        $prefix = str_repeat('a', 70);
        $lock = new MySqlNamedLock($this->db);
        $other = new MySqlNamedLock($this->separateConnection());

        self::assertTrue($lock->acquire($prefix . 'one'));
        self::assertTrue(
            $other->acquire($prefix . 'two', 0),
            'Distinct long names must map to distinct locks.'
        );

        $lock->release($prefix . 'one');
        $other->release($prefix . 'two');
    }
}
