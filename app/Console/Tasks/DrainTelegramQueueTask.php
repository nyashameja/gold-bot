<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Services\Telegram\TelegramService;

/**
 * Drains the Telegram outbox (ADR-07).
 *
 * Separate from the producers by design: enqueuing is a database write inside
 * the caller's transaction, and sending is a network call that may fail. Doing
 * both inline would tie a signal's fate to Telegram's availability.
 */
final class DrainTelegramQueueTask implements TaskInterface
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly int $batchSize = 20
    ) {
    }

    public function run(): TaskResult
    {
        $result = $this->telegram->drain($this->batchSize);

        if ($result['skipped'] > 0) {
            // Messages stay queued: an absent token is a configuration gap,
            // and the backlog should deliver once it is filled.
            return TaskResult::skipped('Telegram is not configured; messages remain queued.');
        }

        $processed = $result['sent'] + $result['failed'] + $result['dead'];

        // A retryable failure is not a task failure — the queue will try again,
        // and marking the task failed would trip health alerts on a condition
        // that is already being handled.
        if ($result['dead'] > 0 && $result['sent'] === 0) {
            return TaskResult::failed(
                sprintf('%d message(s) dead-lettered, none sent.', $result['dead'])
            );
        }

        return TaskResult::success(
            $processed,
            sprintf('%d sent, %d retrying, %d dead', $result['sent'], $result['failed'], $result['dead'])
        );
    }
}
