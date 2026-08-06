<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Core\Database;
use GoldBot\Domain\Notification\MessageType;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Integrations\Telegram\TelegramClientInterface;
use GoldBot\Repositories\Contracts\TelegramRepositoryInterface;

/**
 * The Telegram page: queue depth, delivery history and the configured chats.
 *
 * The number that matters is not how many messages are pending but how long
 * the oldest one has been waiting. A queue of forty that drains every minute
 * is healthy; a queue of two where the older is an hour old means the drain
 * cron has stopped, and depth alone cannot tell those apart.
 */
final class TelegramBoardService
{
    public function __construct(
        private readonly TelegramRepositoryInterface $telegram,
        private readonly TelegramClientInterface $client,
        private readonly Database $database,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function board(int $limit = 50): array
    {
        $now = $this->clock->now();
        $stats = $this->telegram->queueStats($now);

        $messages = array_map(
            fn (array $row): array => $this->decorateMessage($row, $now),
            $this->telegram->recent($limit)
        );

        return [
            'configured' => $this->isConfigured(),
            'queue'      => [
                ...$stats,
                'oldest_pending_label' => $stats['oldest_pending_seconds'] === null
                    ? null
                    : $this->duration((int) $stats['oldest_pending_seconds']),
                'health' => $this->queueHealth($stats),
            ],
            'messages'  => $messages,
            'chats'     => $this->chats(),
            'templates' => $this->templates(),
            'types'     => array_map(
                // The enum value IS the template code — see MessageType.
                static fn (MessageType $t): array => [
                    'value'    => $t->value,
                    'audience' => $t->audience(),
                    'priority' => $t->priority(),
                ],
                MessageType::cases()
            ),
            'age' => DataAge::since($this->lastSentAt(), $now, 3600)->toArray(),
        ];
    }

    /**
     * The compact queue tile for the Overview.
     *
     * @return array<string,mixed>
     */
    public function queueSummary(): array
    {
        $now = $this->clock->now();
        $stats = $this->telegram->queueStats($now);

        return [
            'configured' => $this->isConfigured(),
            'pending'    => $stats['pending'],
            'failed'     => $stats['failed'],
            'dead'       => $stats['dead'],
            'sent_24h'   => $stats['sent_24h'],
            'health'     => $this->queueHealth($stats),
        ];
    }

    /**
     * Whether a bot token is actually present.
     *
     * Distinguished from "the queue is empty" because they look identical on
     * the page and mean opposite things: one is nothing to send, the other is
     * everything piling up unsent.
     */
    public function isConfigured(): bool
    {
        // Asked of the client rather than read from config here, so there is
        // one definition of "configured" — the page cannot claim the bot is
        // set up while the transport disagrees. The token itself never leaves
        // the client.
        return $this->client->isConfigured();
    }

    /**
     * @param array<string,mixed> $stats
     */
    private function queueHealth(array $stats): string
    {
        $oldest = $stats['oldest_pending_seconds'];

        return match (true) {
            (int) $stats['dead'] > 0                => 'CRITICAL',
            $oldest !== null && (int) $oldest > 900 => 'CRITICAL',
            (int) $stats['failed'] > 0              => 'WARNING',
            $oldest !== null && (int) $oldest > 300 => 'WARNING',
            default                                 => 'OK',
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decorateMessage(array $row, DateTimeImmutable $now): array
    {
        $createdAt = new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC'));
        $sentAt = $row['sent_at'] === null
            ? null
            : new DateTimeImmutable((string) $row['sent_at'], new DateTimeZone('UTC'));

        return [
            'id'         => (int) $row['id'],
            'chat_id'    => (string) $row['chat_id'],
            'template'   => (string) $row['template_code'],
            'status'     => (string) $row['status'],
            'attempts'   => (int) $row['attempts'],
            'max_attempts' => (int) $row['max_attempts'],
            'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
            'provider_message_id' => $row['provider_message_id'] === null ? null : (string) $row['provider_message_id'],
            'available_at' => (string) $row['available_at'],
            'created_at' => $createdAt->format(DATE_ATOM),
            'sent_at'    => $sentAt?->format(DATE_ATOM),
            // Enqueue-to-delivery latency: the queue's actual service level,
            // as opposed to whether it eventually got there.
            'latency'    => $sentAt === null
                ? null
                : $this->duration(max(0, $sentAt->getTimestamp() - $createdAt->getTimestamp())),
            'age'        => DataAge::since($createdAt, $now, 3600)->toArray(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function chats(): array
    {
        return $this->database->select(
            'SELECT id, chat_id, title, type, is_active,
                    receives_signals, receives_alerts, receives_summaries,
                    created_at
             FROM telegram_chats
             ORDER BY is_active DESC, id'
        );
    }

    /** @return list<array<string,mixed>> */
    private function templates(): array
    {
        return $this->database->select(
            'SELECT code, name, parse_mode, is_active, updated_at
             FROM telegram_templates
             ORDER BY code'
        );
    }

    private function lastSentAt(): ?DateTimeImmutable
    {
        $value = $this->database->scalar('SELECT MAX(sent_at) FROM telegram_messages');

        return $value === null ? null : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }

    private function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 60    => $seconds . 's',
            $seconds < 3600  => intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm',
            default          => intdiv($seconds, 86400) . 'd',
        };
    }
}
