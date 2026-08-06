<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use DateTimeImmutable;

interface TelegramRepositoryInterface
{
    /**
     * Enqueue a message, ignoring it if the idempotency key already exists.
     *
     * The unique key is the guarantee that matters: derived from what the
     * message is about, it makes a duplicate send impossible even if the
     * producer runs twice (ADR-07).
     *
     * @param array<string,mixed> $payload
     * @return int The new message id, or 0 if it was already queued.
     */
    public function enqueue(
        string $chatId,
        string $templateCode,
        string $idempotencyKey,
        array $payload,
        string $renderedText,
        string $parseMode,
        int $priority,
        DateTimeImmutable $availableAt,
        int $maxAttempts,
        ?int $signalId = null
    ): int;

    /**
     * Claim messages that are due, highest priority first.
     *
     * @return list<array<string,mixed>>
     */
    public function claimDue(DateTimeImmutable $now, int $limit = 20): array;

    public function markSent(int $messageId, DateTimeImmutable $at, ?string $providerMessageId): void;

    /**
     * Record a failure and schedule the retry, or dead-letter it.
     *
     * @return bool True if it will be retried, false if it is now DEAD.
     */
    public function markFailed(int $messageId, string $error, DateTimeImmutable $retryAt): bool;

    /** Permanently fail a message that cannot succeed however often it is tried. */
    public function markDead(int $messageId, string $error): void;

    /** @return list<array<string,mixed>> Chats subscribed to an audience flag. */
    public function chatsFor(string $audienceColumn): array;

    /** @return array{pending:int,failed:int,dead:int,sent_24h:int,oldest_pending_seconds:?int} */
    public function queueStats(DateTimeImmutable $now): array;

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 50): array;

    /** Requeue a dead message — an operator action after fixing the cause. */
    public function requeue(int $messageId, DateTimeImmutable $availableAt): bool;
}
