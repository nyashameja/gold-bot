<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

/**
 * The outcome of a task run.
 *
 * The statuses are deliberately distinct rather than a boolean (docs/02 §9):
 * a task skipped because it was already running is healthy, one skipped for
 * exhausted API budget is a warning, and a failure is an error. Collapsing
 * them into "didn't run" discards exactly the information needed to respond.
 */
final class TaskResult
{
    public const SUCCESS        = 'SUCCESS';
    public const FAILED         = 'FAILED';
    public const SKIPPED_LOCKED = 'SKIPPED_LOCKED';
    public const SKIPPED_BUDGET = 'SKIPPED_BUDGET';
    public const SKIPPED        = 'SKIPPED';

    private function __construct(
        public readonly string $status,
        public readonly int $itemsProcessed = 0,
        public readonly string $output = '',
        public readonly ?string $errorMessage = null
    ) {
    }

    public static function success(int $items = 0, string $output = ''): self
    {
        return new self(self::SUCCESS, $items, $output);
    }

    public static function failed(string $error, int $items = 0): self
    {
        return new self(self::FAILED, $items, '', $error);
    }

    public static function skippedLocked(): self
    {
        return new self(self::SKIPPED_LOCKED, 0, 'Already running.');
    }

    public static function skippedBudget(string $reason = 'API budget exhausted.'): self
    {
        return new self(self::SKIPPED_BUDGET, 0, $reason);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, 0, $reason);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }

    /** Whether this outcome should count toward consecutive_failures. */
    public function countsAsFailure(): bool
    {
        return $this->status === self::FAILED;
    }
}
