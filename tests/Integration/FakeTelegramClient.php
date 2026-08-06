<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use GoldBot\Integrations\Telegram\TelegramClientInterface;

/**
 * A scriptable transport, so the outbox can be driven through success,
 * retryable failure and permanent failure with no network.
 */
final class FakeTelegramClient implements TelegramClientInterface
{
    /** @var list<array{chatId:string,text:string,parseMode:string}> */
    public array $sent = [];

    /** @var list<array{ok:bool,messageId:?string,error:?string,retryable:bool}> */
    private array $responses = [];

    public function __construct(private bool $configured = true)
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function setConfigured(bool $configured): void
    {
        $this->configured = $configured;
    }

    /** Queue an outcome for the next send. Falls back to success when empty. */
    public function willSucceed(?string $messageId = '1001'): self
    {
        $this->responses[] = ['ok' => true, 'messageId' => $messageId, 'error' => null, 'retryable' => false];

        return $this;
    }

    public function willFailRetryable(string $error = 'Too Many Requests'): self
    {
        $this->responses[] = ['ok' => false, 'messageId' => null, 'error' => $error, 'retryable' => true];

        return $this;
    }

    public function willFailPermanently(string $error = 'Bad Request: chat not found'): self
    {
        $this->responses[] = ['ok' => false, 'messageId' => null, 'error' => $error, 'retryable' => false];

        return $this;
    }

    public function sendMessage(string $chatId, string $text, string $parseMode = 'HTML'): array
    {
        $this->sent[] = ['chatId' => $chatId, 'text' => $text, 'parseMode' => $parseMode];

        return array_shift($this->responses)
            ?? ['ok' => true, 'messageId' => '1000', 'error' => null, 'retryable' => false];
    }

    public function getMe(): array
    {
        return ['ok' => $this->configured, 'username' => 'goldbot_test'];
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->responses = [];
    }
}
