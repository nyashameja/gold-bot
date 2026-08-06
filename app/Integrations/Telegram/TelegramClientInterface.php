<?php

declare(strict_types=1);

namespace GoldBot\Integrations\Telegram;

/**
 * Outbound message transport (docs/01 §4).
 *
 * A port rather than a concrete dependency for two reasons: V2 adds email,
 * Discord and webhooks behind the same outbox, and tests need to drive the
 * queue through success, retryable failure and permanent failure without a
 * network.
 */
interface TelegramClientInterface
{
    public function isConfigured(): bool;

    /**
     * `retryable` distinguishes a transient fault from a permanent one, so the
     * outbox knows whether backing off is worth anything.
     *
     * @return array{ok:bool,messageId:?string,error:?string,retryable:bool}
     */
    public function sendMessage(string $chatId, string $text, string $parseMode = 'HTML'): array;

    /** @return array<string,mixed> */
    public function getMe(): array;
}
