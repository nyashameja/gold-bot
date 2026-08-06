<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use GoldBot\Core\Database;
use GoldBot\Repositories\Contracts\TelegramRepositoryInterface;

final class MySqlTelegramRepository implements TelegramRepositoryInterface
{
    /** Columns a caller may filter chats by — an allow-list, never free text. */
    private const AUDIENCE_COLUMNS = ['receives_signals', 'receives_alerts', 'receives_summaries'];

    public function __construct(private readonly Database $database)
    {
    }

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
    ): int {
        // INSERT IGNORE on the unique idempotency key. Re-enqueuing the same
        // logical message is a no-op rather than a duplicate send or an error,
        // which is what lets producers be safely re-run (ADR-07).
        $affected = $this->database->run(
            'INSERT IGNORE INTO telegram_messages
                (chat_id, template_code, idempotency_key, payload, rendered_text, parse_mode,
                 priority, status, max_attempts, signal_id, available_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $chatId,
                $templateCode,
                $idempotencyKey,
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
                $renderedText,
                $parseMode,
                $priority,
                'PENDING',
                $maxAttempts,
                $signalId,
                $availableAt->format('Y-m-d H:i:s'),
            ]
        );

        return $affected === 0 ? 0 : (int) $this->database->pdo()->lastInsertId();
    }

    public function claimDue(DateTimeImmutable $now, int $limit = 20): array
    {
        return $this->database->select(
            "SELECT * FROM telegram_messages
             WHERE status = 'PENDING' AND available_at <= ?
             ORDER BY priority, available_at, id
             LIMIT ?",
            [$now->format('Y-m-d H:i:s'), max(1, min($limit, 200))]
        );
    }

    public function markSent(int $messageId, DateTimeImmutable $at, ?string $providerMessageId): void
    {
        $this->database->run(
            "UPDATE telegram_messages
             SET status = 'SENT', sent_at = ?, provider_message_id = ?, attempts = attempts + 1, last_error = NULL
             WHERE id = ?",
            [$at->format('Y-m-d H:i:s'), $providerMessageId, $messageId]
        );
    }

    public function markFailed(int $messageId, string $error, DateTimeImmutable $retryAt): bool
    {
        $this->database->run(
            'UPDATE telegram_messages SET attempts = attempts + 1, last_error = ? WHERE id = ?',
            [mb_substr($error, 0, 255), $messageId]
        );

        $row = $this->database->selectOne(
            'SELECT attempts, max_attempts FROM telegram_messages WHERE id = ?',
            [$messageId]
        );

        if ($row === null) {
            return false;
        }

        // Exhausted: DEAD is terminal and surfaced on the health page rather
        // than retried forever.
        if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
            $this->database->run(
                "UPDATE telegram_messages SET status = 'DEAD' WHERE id = ?",
                [$messageId]
            );

            return false;
        }

        $this->database->run(
            "UPDATE telegram_messages SET status = 'PENDING', available_at = ? WHERE id = ?",
            [$retryAt->format('Y-m-d H:i:s'), $messageId]
        );

        return true;
    }

    public function markDead(int $messageId, string $error): void
    {
        $this->database->run(
            "UPDATE telegram_messages
             SET status = 'DEAD', attempts = attempts + 1, last_error = ?
             WHERE id = ?",
            [mb_substr($error, 0, 255), $messageId]
        );
    }

    public function chatsFor(string $audienceColumn): array
    {
        if (!in_array($audienceColumn, self::AUDIENCE_COLUMNS, true)) {
            return [];
        }

        return $this->database->select(
            "SELECT id, chat_id, title, type FROM telegram_chats
             WHERE is_active = 1 AND `{$audienceColumn}` = 1
             ORDER BY id"
        );
    }

    public function queueStats(DateTimeImmutable $now): array
    {
        $row = $this->database->selectOne(
            "SELECT
                SUM(status = 'PENDING') AS pending,
                SUM(status = 'FAILED')  AS failed,
                SUM(status = 'DEAD')    AS dead,
                SUM(status = 'SENT' AND sent_at >= ?) AS sent_24h,
                MIN(CASE WHEN status = 'PENDING' THEN available_at END) AS oldest_pending
             FROM telegram_messages",
            [$now->modify('-24 hours')->format('Y-m-d H:i:s')]
        );

        $oldest = $row['oldest_pending'] ?? null;

        return [
            'pending'  => (int) ($row['pending'] ?? 0),
            'failed'   => (int) ($row['failed'] ?? 0),
            'dead'     => (int) ($row['dead'] ?? 0),
            'sent_24h' => (int) ($row['sent_24h'] ?? 0),
            // Age of the oldest message still waiting: the number that tells
            // you whether the queue is moving, which depth alone does not.
            'oldest_pending_seconds' => $oldest === null
                ? null
                : max(0, $now->getTimestamp() - (new DateTimeImmutable((string) $oldest))->getTimestamp()),
        ];
    }

    public function recent(int $limit = 50): array
    {
        return $this->database->select(
            'SELECT id, chat_id, template_code, status, attempts, max_attempts,
                    last_error, provider_message_id, available_at, sent_at, created_at
             FROM telegram_messages
             ORDER BY id DESC
             LIMIT ?',
            [max(1, min($limit, 200))]
        );
    }

    public function requeue(int $messageId, DateTimeImmutable $availableAt): bool
    {
        return $this->database->run(
            "UPDATE telegram_messages
             SET status = 'PENDING', attempts = 0, last_error = NULL, available_at = ?
             WHERE id = ? AND status = 'DEAD'",
            [$availableAt->format('Y-m-d H:i:s'), $messageId]
        ) > 0;
    }
}
