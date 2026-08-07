<?php

declare(strict_types=1);

namespace Paragon\Core\Logging;

use Paragon\Core\Clock\ClockInterface;
use Stringable;
use Throwable;

/**
 * Structured logger writing newline-delimited JSON to daily-rotated files.
 *
 * JSON rather than prose so the health page and any later log shipping can
 * parse records instead of scraping them. One file per day keeps rotation
 * trivial on cPanel, where logrotate is usually not available to the user.
 */
final class FileLogger implements LoggerInterface
{
    /** @var array<string,mixed> */
    private array $baseContext = [];

    public function __construct(
        private readonly string $directory,
        private readonly ClockInterface $clock,
        private readonly LogLevel $minimumLevel = LogLevel::Info,
        private readonly string $channel = 'app',
        private readonly int $retentionDays = 90
    ) {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0750, true);
        }
    }

    public function log(LogLevel $level, string|Stringable $message, array $context = []): void
    {
        if (!$level->isAtLeast($this->minimumLevel)) {
            return;
        }

        $context = [...$this->baseContext, ...$context];

        $record = [
            'timestamp' => $this->clock->now()->format('Y-m-d\TH:i:s.up'),
            'level'     => $level->value,
            'channel'   => $context['channel'] ?? $this->channel,
            'event'     => $context['event'] ?? null,
            'message'   => (string) $message,
            'context'   => $this->normaliseContext($context),
        ];

        $line = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($line === false) {
            // Never let a logging failure take down the caller. Fall back to a
            // record that is guaranteed encodable rather than throwing.
            $line = json_encode([
                'timestamp' => $this->clock->now()->format('Y-m-d\TH:i:s.up'),
                'level'     => $level->value,
                'message'   => (string) $message,
                'context'   => ['_error' => 'context was not JSON-encodable'],
            ]);
        }

        @file_put_contents($this->currentFile(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Notice, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Critical, $message, $context);
    }

    public function withContext(array $context): LoggerInterface
    {
        $clone = new self(
            $this->directory,
            $this->clock,
            $this->minimumLevel,
            $this->channel,
            $this->retentionDays
        );

        $clone->baseContext = [...$this->baseContext, ...$context];

        return $clone;
    }

    /**
     * Delete log files older than the retention window. Called by the cleanup
     * task; on shared hosting an unbounded log directory eventually exhausts
     * the account's disk quota, which takes the whole site down.
     */
    public function prune(): int
    {
        $cutoff = $this->clock->timestamp() - ($this->retentionDays * 86400);
        $deleted = 0;

        foreach (glob($this->directory . '/goldbot-*.log') ?: [] as $file) {
            if (@filemtime($file) < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function currentFile(): string
    {
        return sprintf('%s/goldbot-%s.log', rtrim($this->directory, '/'), $this->clock->now()->format('Y-m-d'));
    }

    /**
     * Make context JSON-safe and strip anything that must never be written to
     * disk. Secrets reaching the log is a real risk: an exception thrown while
     * building a provider request can carry the API key in its trace.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function normaliseContext(array $context): array
    {
        $redactKeys = ['password', 'token', 'api_key', 'apikey', 'secret', 'authorization', 'bot_token'];
        $normalised = [];

        foreach ($context as $key => $value) {
            if (in_array($key, ['channel', 'event'], true)) {
                continue;
            }

            $lowerKey = strtolower((string) $key);

            foreach ($redactKeys as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    $normalised[$key] = '[redacted]';

                    continue 2;
                }
            }

            $normalised[$key] = $this->normaliseValue($value);
        }

        return $normalised;
    }

    private function normaliseValue(mixed $value): mixed
    {
        if ($value instanceof Throwable) {
            return [
                'class'   => $value::class,
                'message' => $value->getMessage(),
                'code'    => $value->getCode(),
                'file'    => $value->getFile() . ':' . $value->getLine(),
                // Message only for the previous exception — a full nested trace
                // makes records enormous with little added diagnostic value.
                'previous' => $value->getPrevious()?->getMessage(),
            ];
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sp');
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normaliseValue($item), $value);
        }

        if (is_object($value)) {
            return $value instanceof Stringable ? (string) $value : $value::class;
        }

        return $value;
    }
}
