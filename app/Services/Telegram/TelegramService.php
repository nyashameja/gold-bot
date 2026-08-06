<?php

declare(strict_types=1);

namespace GoldBot\Services\Telegram;

use DateTimeImmutable;
use GoldBot\Domain\Notification\MessageType;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Integrations\Telegram\TelegramClientInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Repositories\Contracts\TelegramRepositoryInterface;

/**
 * The transactional outbox (ADR-07).
 *
 * Producers call enqueue(), which only writes rows — so it can safely run
 * inside the caller's transaction alongside the signal that caused it. A
 * rolled-back signal therefore leaves no orphaned alert, and a committed one
 * cannot fail to produce its message.
 *
 * drain() is the other half, run by a separate cron. Delivery is at-least-once
 * with dedupe on the idempotency key, which is achievable; exactly-once over an
 * HTTP API is not.
 */
final class TelegramService
{
    public function __construct(
        private readonly TelegramRepositoryInterface $repository,
        private readonly TelegramClientInterface $client,
        private readonly MessageRenderer $renderer,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Queue a message to every chat subscribed to its audience.
     *
     * The idempotency key identifies what the message is ABOUT, not when it was
     * created — `signal:{uuid}:TP1` is the same message however many times the
     * producer runs.
     *
     * @param array<string,mixed> $payload
     * @return int Messages actually queued (zero when already present).
     */
    public function enqueue(MessageType $type, string $idempotencyKey, array $payload, ?int $signalId = null): int
    {
        if (!(bool) $this->settings->get('telegram.enabled', false)) {
            return 0;
        }

        $chats = $this->repository->chatsFor($type->audience());

        if ($chats === []) {
            // Not an error: a platform with no chats configured yet is a
            // normal state, and failing here would block signal creation.
            return 0;
        }

        $rendered = $this->renderer->render($type->value, $payload);
        $maxAttempts = (int) $this->settings->get('telegram.max_attempts', 5);
        $now = $this->clock->now();
        $queued = 0;

        foreach ($chats as $chat) {
            // Scoped per chat, so adding a second chat later does not suppress
            // its copy on the grounds that the first already went out.
            $key = sprintf('%s|%s', $idempotencyKey, $chat['chat_id']);

            $id = $this->repository->enqueue(
                (string) $chat['chat_id'],
                $type->value,
                $key,
                $payload,
                $rendered['text'],
                $rendered['parse_mode'],
                $type->priority(),
                $now,
                $maxAttempts,
                $signalId
            );

            if ($id > 0) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Send whatever is due.
     *
     * @return array{sent:int,failed:int,dead:int,skipped:int}
     */
    public function drain(int $limit = 20): array
    {
        $sent = 0;
        $failed = 0;
        $dead = 0;

        if (!$this->client->isConfigured()) {
            // Leave messages queued rather than dead-lettering them: the token
            // being absent is a configuration gap, and the backlog should
            // deliver once it is filled.
            return ['sent' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 1];
        }

        $now = $this->clock->now();
        $base = max(1, (int) $this->settings->get('telegram.retry_base_seconds', 30));

        foreach ($this->repository->claimDue($now, $limit) as $message) {
            $id = (int) $message['id'];

            $result = $this->client->sendMessage(
                (string) $message['chat_id'],
                (string) $message['rendered_text'],
                (string) $message['parse_mode']
            );

            if ($result['ok']) {
                $this->repository->markSent($id, $this->clock->now(), $result['messageId']);
                $sent++;

                $this->logger->info('Telegram message sent', [
                    'event'      => 'telegram.sent',
                    'message_id' => $id,
                    'template'   => $message['template_code'],
                    'chat_id'    => $message['chat_id'],
                ]);

                continue;
            }

            $error = (string) ($result['error'] ?? 'unknown error');

            // A permanent failure is dead-lettered immediately. Retrying a
            // malformed message four more times only delays the inevitable
            // and spends quota doing it.
            if (!$result['retryable']) {
                $this->repository->markDead($id, $error);
                $dead++;

                $this->logger->error('Telegram message permanently failed', [
                    'event'      => 'telegram.dead',
                    'message_id' => $id,
                    'error'      => $error,
                ]);

                continue;
            }

            // Exponential backoff: base, 2x, 4x, 8x…
            $attempts = (int) $message['attempts'];
            $delay = $base * (2 ** $attempts);

            $willRetry = $this->repository->markFailed(
                $id,
                $error,
                $this->clock->now()->modify(sprintf('+%d seconds', $delay))
            );

            if ($willRetry) {
                $failed++;
            } else {
                $dead++;

                $this->logger->error('Telegram message exhausted its attempts', [
                    'event'      => 'telegram.dead',
                    'message_id' => $id,
                    'attempts'   => $attempts + 1,
                    'error'      => $error,
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'dead' => $dead, 'skipped' => 0];
    }

    /** @return array{pending:int,failed:int,dead:int,sent_24h:int,oldest_pending_seconds:?int} */
    public function queueStats(): array
    {
        return $this->repository->queueStats($this->clock->now());
    }

    public function requeue(int $messageId): bool
    {
        return $this->repository->requeue($messageId, $this->clock->now());
    }

    /** Verify the configured token — used by the health check. */
    public function verifyConnection(): array
    {
        return $this->client->getMe();
    }
}
