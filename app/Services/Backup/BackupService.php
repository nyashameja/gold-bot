<?php

declare(strict_types=1);

namespace GoldBot\Services\Backup;

use DateTimeImmutable;
use Paragon\Core\Clock\ClockInterface;
use Paragon\Core\Logging\LoggerInterface;
use RuntimeException;

/**
 * Nightly database backups (docs/01 §12).
 *
 * Three decisions worth stating, because each is a way backups usually fail:
 *
 *  1. The credential is passed through the ENVIRONMENT, never on the command
 *     line. Arguments are visible in `ps` to every other account on a shared
 *     host, so `--password=` would publish the database password to the
 *     neighbours once a night, forever.
 *
 *  2. The dump is written to a temporary file and only renamed into place
 *     after mysqldump exits successfully. A backup directory must never
 *     contain a truncated file with a plausible name and timestamp — that is
 *     worse than an empty directory, because it will be trusted.
 *
 *  3. Rotation keeps a COUNT, not an age. An account that stops backing up
 *     would, under an age policy, quietly delete its way to nothing while the
 *     failure went unnoticed.
 *
 * Restore is a first-class operation here rather than a paragraph in a runbook,
 * because a restore procedure nobody has executed is a hypothesis.
 */
final class BackupService
{
    private const PREFIX = 'goldbot-';

    /** @param array<string,mixed> $connection */
    public function __construct(
        private readonly array $connection,
        private readonly string $backupPath,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $keep = 7,
        private readonly string $mysqldump = 'mysqldump',
        private readonly string $mysql = 'mysql'
    ) {
    }

    /**
     * Take a backup.
     *
     * @return array{file:string,bytes:int,compressed:bool,duration_ms:int}
     */
    public function create(): array
    {
        $this->ensureDirectory();

        $started = microtime(true);
        $stamp = $this->clock->now()->format('Ymd-His');
        $final = sprintf('%s/%s%s.sql.gz', $this->backupPath, self::PREFIX, $stamp);
        $temporary = $final . '.partial';

        // --single-transaction gives a consistent snapshot of InnoDB tables
        // without locking them, so a backup running at 03:00 does not block the
        // market import that runs every minute.
        $command = sprintf(
            '%s --single-transaction --quick --routines --events --no-tablespaces '
            . '--default-character-set=utf8mb4 --host=%s --port=%s --user=%s %s 2>&1',
            escapeshellcmd($this->mysqldump),
            escapeshellarg((string) ($this->connection['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($this->connection['port'] ?? 3306)),
            escapeshellarg((string) ($this->connection['username'] ?? '')),
            escapeshellarg((string) ($this->connection['database'] ?? ''))
        );

        $output = $this->run($command, $temporary, compress: true);

        if ($output['status'] !== 0) {
            @unlink($temporary);

            throw new RuntimeException('mysqldump failed: ' . trim($output['stderr']));
        }

        $bytes = (int) @filesize($temporary);

        // A gzip stream is never this short. An "empty" backup that silently
        // succeeded is the failure this catches.
        if ($bytes < 100) {
            @unlink($temporary);

            throw new RuntimeException('The dump was empty — refusing to keep it.');
        }

        if (!@rename($temporary, $final)) {
            @unlink($temporary);

            throw new RuntimeException('Could not move the completed dump into place.');
        }

        // Owner-only. A dump contains every password hash, every session and
        // the whole audit trail — it is the single most sensitive file the
        // application produces, and the default umask would leave it readable
        // by every other account on a shared host.
        @chmod($final, 0600);

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $this->logger->info('Database backup written', [
            'event'       => 'backup.created',
            'file'        => basename($final),
            'bytes'       => $bytes,
            'duration_ms' => $durationMs,
        ]);

        return [
            'file'        => $final,
            'bytes'       => $bytes,
            'compressed'  => true,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Delete all but the newest $keep backups.
     *
     * @return list<string> The files removed.
     */
    public function rotate(): array
    {
        $backups = $this->list();
        $removed = [];

        foreach (array_slice($backups, $this->keep) as $backup) {
            if (@unlink($backup['path'])) {
                $removed[] = $backup['name'];
            }
        }

        if ($removed !== []) {
            $this->logger->info('Old backups rotated out', [
                'event'   => 'backup.rotated',
                'removed' => $removed,
                'kept'    => $this->keep,
            ]);
        }

        return $removed;
    }

    /**
     * Existing backups, newest first.
     *
     * @return list<array{name:string,path:string,bytes:int,at:DateTimeImmutable}>
     */
    public function list(): array
    {
        if (!is_dir($this->backupPath)) {
            return [];
        }

        $backups = [];

        foreach (glob($this->backupPath . '/' . self::PREFIX . '*.sql.gz') ?: [] as $path) {
            $backups[] = [
                'name'  => basename($path),
                'path'  => $path,
                'bytes' => (int) @filesize($path),
                'at'    => (new DateTimeImmutable())->setTimestamp((int) @filemtime($path)),
            ];
        }

        usort($backups, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return $backups;
    }

    /**
     * Restore a dump into a database.
     *
     * The target is explicit and has no default. Restoring is destructive, and
     * a method that would overwrite the live database when called with no
     * arguments is an accident waiting to be committed.
     *
     * @return array{tables:int,duration_ms:int}
     */
    public function restore(string $file, string $intoDatabase): array
    {
        if (!is_file($file)) {
            throw new RuntimeException("No such backup: {$file}");
        }

        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', trim($intoDatabase)) !== 1) {
            throw new RuntimeException('A valid target database must be named explicitly.');
        }

        $started = microtime(true);

        $command = sprintf(
            'gunzip -c %s | %s --host=%s --port=%s --user=%s --default-character-set=utf8mb4 %s 2>&1',
            escapeshellarg($file),
            escapeshellcmd($this->mysql),
            escapeshellarg((string) ($this->connection['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($this->connection['port'] ?? 3306)),
            escapeshellarg((string) ($this->connection['username'] ?? '')),
            escapeshellarg($intoDatabase)
        );

        $result = $this->run($command, null, compress: false);

        if ($result['status'] !== 0) {
            throw new RuntimeException('Restore failed: ' . trim($result['stderr']));
        }

        $this->logger->warning('Database restored from backup', [
            'event'    => 'backup.restored',
            'file'     => basename($file),
            'database' => $intoDatabase,
        ]);

        return [
            'tables'      => $this->countTables($intoDatabase),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /** How many tables a database ended up with — the restore's proof of work. */
    private function countTables(string $database): int
    {
        // Validated against a strict identifier pattern rather than stripped of
        // quotes. This value is interpolated into a SQL string below, and
        // "remove the dangerous characters" is a losing game — deciding what is
        // ALLOWED is the only version of this that stays correct.
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new RuntimeException("Not a valid database name: {$database}");
        }

        $command = sprintf(
            '%s --host=%s --port=%s --user=%s --skip-column-names --batch '
            . '-e %s 2>&1',
            escapeshellcmd($this->mysql),
            escapeshellarg((string) ($this->connection['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($this->connection['port'] ?? 3306)),
            escapeshellarg((string) ($this->connection['username'] ?? '')),
            escapeshellarg(sprintf(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '%s'",
                $database
            ))
        );

        $result = $this->run($command, null, compress: false);

        return (int) trim($result['stdout']);
    }

    /**
     * Run a shell command with the database password supplied through the
     * environment.
     *
     * MYSQL_PWD is read by both mysql and mysqldump and never appears in the
     * process list — unlike --password, which does, to every account on the
     * host.
     *
     * @return array{status:int,stdout:string,stderr:string}
     */
    private function run(string $command, ?string $writeTo, bool $compress): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $writeTo === null ? ['pipe', 'w'] : ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = [
            'PATH'      => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
            'MYSQL_PWD' => (string) ($this->connection['password'] ?? ''),
        ];

        $process = proc_open($command, $descriptors, $pipes, null, $environment);

        if (!is_resource($process)) {
            return ['status' => 1, 'stdout' => '', 'stderr' => 'Could not start the process.'];
        }

        fclose($pipes[0]);

        $stdout = '';

        if ($writeTo === null) {
            $stdout = (string) stream_get_contents($pipes[1]);
        } else {
            // Streamed straight to disk, compressed on the way. Buffering a
            // multi-gigabyte dump in PHP memory would exhaust the shared-host
            // memory limit long before the database outgrew the disk.
            $handle = $compress ? gzopen($writeTo, 'wb6') : fopen($writeTo, 'wb');

            if ($handle === false) {
                proc_close($process);

                return ['status' => 1, 'stdout' => '', 'stderr' => "Could not open {$writeTo} for writing."];
            }

            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 262144);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $compress ? gzwrite($handle, $chunk) : fwrite($handle, $chunk);
            }

            $compress ? gzclose($handle) : fclose($handle);
        }

        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [
            'status' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->backupPath) && !@mkdir($this->backupPath, 0750, true) && !is_dir($this->backupPath)) {
            throw new RuntimeException("The backup directory {$this->backupPath} could not be created.");
        }

        if (!is_writable($this->backupPath)) {
            throw new RuntimeException("The backup directory {$this->backupPath} is not writable.");
        }
    }
}
