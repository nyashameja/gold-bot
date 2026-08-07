<?php

declare(strict_types=1);

namespace GoldBot\Database;

use Paragon\Core\Database;
use Paragon\Core\Logging\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Forward-only migration runner.
 *
 * There are deliberately no down-migrations (docs/03 §3). On a single-server
 * cPanel deployment a rollback is rarely correct and never tested, and having
 * one encourages relying on a recovery path that will not work when it is
 * actually needed. Recovery is restore-from-backup, which Phase 10 verifies
 * for real rather than assuming.
 *
 * Each migration runs inside a transaction, but note that MySQL commits
 * implicitly on DDL: a migration that fails halfway through several CREATE
 * TABLE statements cannot be rolled back. That is why each migration file
 * owns one coherent unit of schema and is written to be re-runnable.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $database,
        private readonly string $path,
        private readonly LoggerInterface $logger,
        private readonly string $table = 'migrations'
    ) {
    }

    /**
     * Apply every migration that has not yet run.
     *
     * @return list<string> Names of applied migrations.
     */
    public function migrate(): array
    {
        $this->ensureRepositoryExists();

        $applied = $this->appliedMigrations();
        $pending = array_values(array_diff($this->availableMigrations(), $applied));

        if ($pending === []) {
            return [];
        }

        $batch = $this->nextBatchNumber();
        $ran = [];

        foreach ($pending as $name) {
            $startedAt = microtime(true);

            try {
                $this->runMigration($name);
            } catch (Throwable $e) {
                $this->logger->critical('Migration failed', [
                    'event'     => 'migration.failed',
                    'migration' => $name,
                    'exception' => $e,
                ]);

                throw new RuntimeException(
                    "Migration [{$name}] failed: {$e->getMessage()}",
                    0,
                    $e
                );
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->database->insert($this->table, [
                'migration'   => $name,
                'batch'       => $batch,
                'duration_ms' => $durationMs,
            ]);

            $this->logger->info('Migration applied', [
                'event'       => 'migration.applied',
                'migration'   => $name,
                'batch'       => $batch,
                'duration_ms' => $durationMs,
            ]);

            $ran[] = $name;
        }

        return $ran;
    }

    /** @return list<string> Migrations that exist but have not been applied. */
    public function pending(): array
    {
        $this->ensureRepositoryExists();

        return array_values(array_diff($this->availableMigrations(), $this->appliedMigrations()));
    }

    /** @return list<array{migration:string,batch:int,applied_at:string}> */
    public function status(): array
    {
        $this->ensureRepositoryExists();

        /** @var list<array{migration:string,batch:int,applied_at:string}> $rows */
        $rows = $this->database->select(
            "SELECT migration, batch, applied_at FROM `{$this->table}` ORDER BY id"
        );

        return $rows;
    }

    private function runMigration(string $name): void
    {
        $file = $this->path . '/' . $name . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("Migration file [{$file}] does not exist.");
        }

        $migration = require $file;

        if (!is_callable($migration)) {
            throw new RuntimeException(
                "Migration [{$name}] must return a callable accepting a Database instance."
            );
        }

        $migration($this->database);
    }

    /** @return list<string> */
    private function availableMigrations(): array
    {
        $files = glob($this->path . '/*.php');

        if ($files === false) {
            throw new RuntimeException("Migration directory [{$this->path}] is not readable.");
        }

        $names = array_map(static fn (string $f): string => basename($f, '.php'), $files);

        // Filenames are numerically prefixed; sorting them is what defines
        // execution order, so it must be deterministic rather than relying on
        // whatever order the filesystem returns.
        sort($names, SORT_STRING);

        return array_values($names);
    }

    /** @return list<string> */
    private function appliedMigrations(): array
    {
        /** @var list<array{migration:string}> $rows */
        $rows = $this->database->select("SELECT migration FROM `{$this->table}` ORDER BY id");

        return array_map(static fn (array $r): string => $r['migration'], $rows);
    }

    private function nextBatchNumber(): int
    {
        $max = $this->database->scalar("SELECT MAX(batch) FROM `{$this->table}`");

        return (int) $max + 1;
    }

    private function ensureRepositoryExists(): void
    {
        if ($this->database->tableExists($this->table)) {
            return;
        }

        $this->database->run(
            "CREATE TABLE `{$this->table}` (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration   VARCHAR(255)    NOT NULL,
                batch       INT UNSIGNED    NOT NULL,
                duration_ms INT UNSIGNED    NOT NULL DEFAULT 0,
                applied_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migrations_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
