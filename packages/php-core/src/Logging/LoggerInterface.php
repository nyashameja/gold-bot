<?php

declare(strict_types=1);

namespace Paragon\Core\Logging;

use Stringable;

/**
 * PSR-3 compatible logging.
 *
 * The `event` key in $context is significant rather than free-form: docs/01
 * §11 defines a fixed vocabulary (signal.generated, cron.started, api.failed,
 * telegram.failed, auth.login, settings.changed, …) so logs are greppable and
 * the health page can count occurrences instead of pattern-matching prose.
 */
interface LoggerInterface
{
    /** @param array<string,mixed> $context */
    public function log(LogLevel $level, string|Stringable $message, array $context = []): void;

    /** @param array<string,mixed> $context */
    public function debug(string|Stringable $message, array $context = []): void;

    /** @param array<string,mixed> $context */
    public function info(string|Stringable $message, array $context = []): void;

    /** @param array<string,mixed> $context */
    public function notice(string|Stringable $message, array $context = []): void;

    /** @param array<string,mixed> $context */
    public function warning(string|Stringable $message, array $context = []): void;

    /** @param array<string,mixed> $context */
    public function error(string|Stringable $message, array $context = []): void;

    /** @param array<string,mixed> $context */
    public function critical(string|Stringable $message, array $context = []): void;

    /**
     * Return a logger that merges $context into every subsequent record.
     *
     * Used to stamp a task's run id onto every line it emits, so one run's
     * output can be isolated from an interleaved log.
     *
     * @param array<string,mixed> $context
     */
    public function withContext(array $context): self;
}
