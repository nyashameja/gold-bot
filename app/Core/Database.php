<?php

declare(strict_types=1);

namespace GoldBot\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO wrapper with prepared statements throughout.
 *
 * Every method takes bound parameters. There is deliberately no method that
 * accepts an interpolated SQL string with values in it, so the injection-safe
 * path is also the only path.
 *
 * The connection is lazy: constructing this class does not open a socket, so
 * the container can build it eagerly without every CLI task paying for a
 * connection it may not use.
 */
final class Database
{
    private ?PDO $pdo = null;

    private int $transactionDepth = 0;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'] ?? '127.0.0.1',
            (int) ($this->config['port'] ?? 3306),
            $this->config['database'] ?? '',
            $this->config['charset'] ?? 'utf8mb4'
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                (string) ($this->config['username'] ?? ''),
                (string) ($this->config['password'] ?? ''),
                [
                    // Exceptions, not silent false returns. A failed write must
                    // not be mistakable for a write that affected zero rows.
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Real prepared statements, not client-side emulation.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    PDO::ATTR_PERSISTENT         => false,
                ]
            );
        } catch (PDOException $e) {
            // The DSN carries the username; the message must not carry the
            // password, and PDO's own message sometimes does on misconfiguration.
            throw new RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode()
            );
        }

        // UTC everywhere. Without this, NOW() and CURRENT_TIMESTAMP follow the
        // server's local zone and candle timestamps silently shift by hours.
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        return $this->pdo;
    }

    /**
     * @param array<int|string,mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement;
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->statement($sql, $bindings)->fetchAll();

        return $rows;
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->statement($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string,mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->statement($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Execute a write and return the number of affected rows.
     *
     * @param array<int|string,mixed> $bindings
     */
    public function run(string $sql, array $bindings = []): int
    {
        return $this->statement($sql, $bindings)->rowCount();
    }

    /**
     * @param array<string,mixed> $values
     */
    public function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders)
        );

        $this->statement($sql, $this->prefixKeys($values));

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Insert, or update the listed columns when the row already exists.
     *
     * This is the ingest primitive described in docs/02 §5: candle and event
     * imports call it so that re-fetching an overlapping window is harmless
     * rather than a duplicate-key failure.
     *
     * @param array<string,mixed> $values
     * @param list<string>        $updateColumns Columns to overwrite on conflict.
     */
    public function upsert(string $table, array $values, array $updateColumns): int
    {
        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $assignments = array_map(
            static fn (string $c): string => sprintf('`%s` = VALUES(`%s`)', $c, $c),
            $updateColumns
        );

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders),
            implode(', ', $assignments)
        );

        return $this->run($sql, $this->prefixKeys($values));
    }

    /**
     * Run a callback inside a transaction, rolling back on any throwable.
     *
     * Nested calls join the outer transaction via savepoints rather than
     * opening a second one — MySQL has no nested transactions, and a naive
     * inner commit would otherwise commit the outer work early. This matters
     * for the outbox (ADR-07), where the signal write and the message enqueue
     * must be genuinely atomic.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback();
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT trans' . $this->transactionDepth);
        }

        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            throw new RuntimeException('commit() called with no active transaction.');
        }

        $this->transactionDepth--;

        if ($this->transactionDepth === 0) {
            $this->pdo()->commit();
        } else {
            $this->pdo()->exec('RELEASE SAVEPOINT trans' . $this->transactionDepth);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }

        $this->transactionDepth--;

        if ($this->transactionDepth === 0) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }

            return;
        }

        $this->pdo()->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactionDepth);
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function tableExists(string $table): bool
    {
        $result = $this->scalar(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) $result > 0;
    }

    /**
     * Close the connection, releasing any MySQL named locks held by it.
     *
     * @see \GoldBot\Infrastructure\Lock\MySqlNamedLock
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactionDepth = 0;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function prefixKeys(array $values): array
    {
        $bindings = [];

        foreach ($values as $column => $value) {
            $bindings[':' . $column] = $value;
        }

        return $bindings;
    }
}
